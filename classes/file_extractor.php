<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Attached file and document content extraction helper for Typesense global search.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

/**
 * Extracts searchable plain text from attached Moodle files (.pdf, .docx, .pptx, .xlsx, .odt, .txt, .vtt, .html).
 *
 * Uses a zero-Java local extraction chain:
 * - Office OpenXML (.docx, .pptx, .xlsx) and OpenDocument (.odt, .odp, .ods) via native PHP ZipArchive XML parsing.
 * - Plain text, Markdown, CSV, HTML, and WebVTT/SRT captions in pure PHP.
 * - PDF via pdftotext (poppler-utils), Moodle's Ghostscript ($CFG->pathtogs),
 *   or a built-in pure-PHP FlateDecode and ToUnicode PDF text stream parser as a universal fallback.
 *
 * @package    search_typesense
 * @copyright  2026 Chris Murad
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_extractor {
    /** @var int Default maximum file size in bytes to inspect (20 MB). */
    public const MAX_FILE_SIZE_BYTES = 20971520;

    /** @var int Maximum extracted characters per individual file. */
    public const MAX_CHARS_PER_FILE = 30000;

    /** @var int Maximum total extracted file characters appended to a single search document. */
    public const MAX_CHARS_PER_DOCUMENT = 60000;

    /** @var array<string, string> In-memory LRU cache of extracted text keyed by file SHA-1 contenthash. */
    protected static array $contenthashcache = [];

    /** @var int Maximum number of unique contenthash entries retained in memory during bulk indexing. */
    protected const MAX_CACHE_ENTRIES = 2500;

    /** @var array<string, string> Cached resolved pdftotext binary path per plugin namespace. */
    protected static array $pdftotextcache = [];

    /**
     * Extract combined searchable text from all files attached to a Moodle search document.
     *
     * @param \core_search\document $document The search document with attached stored_file objects.
     * @param string $plugin Plugin config namespace.
     * @return string Combined plain text ready to append to the indexed content field.
     */
    public static function extract_files_text(\core_search\document $document, string $plugin = 'search_typesense'): string {
        $files = $document->get_files();
        if (empty($files)) {
            return '';
        }

        $chunks = [];
        $totalchars = 0;

        foreach ($files as $file) {
            if (!$file instanceof \stored_file || $file->is_directory()) {
                continue;
            }
            $filename = trim($file->get_filename());
            if ($filename === '' || $filename === '.') {
                continue;
            }

            $pieces = [$filename];
            if ($file->get_filesize() > 0 && $file->get_filesize() <= self::MAX_FILE_SIZE_BYTES) {
                $body = self::extract_text_from_stored_file($file, $plugin);
                if ($body !== '') {
                    $pieces[] = $body;
                }
            }

            $combined = trim(implode(' ', $pieces));
            if ($combined === '') {
                continue;
            }

            $chunks[] = $combined;
            $totalchars += \core_text::strlen($combined);
            if ($totalchars >= self::MAX_CHARS_PER_DOCUMENT) {
                break;
            }
        }

        if (empty($chunks)) {
            return '';
        }

        $merged = implode("\n", $chunks);
        if (\core_text::strlen($merged) > self::MAX_CHARS_PER_DOCUMENT) {
            $merged = \core_text::substr($merged, 0, self::MAX_CHARS_PER_DOCUMENT);
        }
        return trim($merged);
    }

    /**
     * Extract plain text from a single Moodle stored_file.
     *
     * @param \stored_file $file Stored file instance.
     * @param string $plugin Plugin config namespace.
     * @return string Extracted plain text.
     */
    public static function extract_text_from_stored_file(\stored_file $file, string $plugin = 'search_typesense'): string {
        if ($file->is_directory() || $file->get_filesize() <= 0 || $file->get_filesize() > self::MAX_FILE_SIZE_BYTES) {
            return '';
        }

        $filename = (string)$file->get_filename();
        $mimetype = (string)$file->get_mimetype();
        $ext = \core_text::strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));

        if (!self::is_supported_extension($ext, $mimetype)) {
            return '';
        }

        $contenthash = (string)$file->get_contenthash();
        if ($contenthash !== '' && array_key_exists($contenthash, self::$contenthashcache)) {
            return self::$contenthashcache[$contenthash];
        }

        // Plain-text and markup formats can be extracted in memory without a temp file.
        if (self::is_inline_text_format($ext, $mimetype)) {
            $raw = (string)$file->get_content();
            $extracted = self::extract_text_from_string($raw, $filename, $mimetype, $plugin);
            self::store_contenthash_cache($contenthash, $extracted);
            return $extracted;
        }

        $temppath = $file->copy_content_to_temp('search_typesense');
        if (empty($temppath) || !is_readable($temppath)) {
            return '';
        }

        try {
            $extracted = self::extract_text_from_path($temppath, $filename, $mimetype, $plugin);
            self::store_contenthash_cache($contenthash, $extracted);
            return $extracted;
        } finally {
            @unlink($temppath);
        }
    }

    /**
     * Store extracted plain text in the bounded in-memory contenthash cache.
     *
     * @param string $contenthash Moodle file SHA-1 contenthash.
     * @param string $text Extracted text (may be empty for scanned image PDFs).
     * @return void
     */
    protected static function store_contenthash_cache(string $contenthash, string $text): void {
        if ($contenthash === '') {
            return;
        }
        if (count(self::$contenthashcache) >= self::MAX_CACHE_ENTRIES) {
            self::$contenthashcache = array_slice(
                self::$contenthashcache,
                (int)(self::MAX_CACHE_ENTRIES / 2),
                null,
                true
            );
        }
        self::$contenthashcache[$contenthash] = $text;
    }

    /**
     * Extract plain text from a file path on disk.
     *
     * @param string $filepath Absolute path to the file on disk.
     * @param string $filename Original filename (used for extension detection).
     * @param string $mimetype Optional MIME type.
     * @param string $plugin Plugin config namespace.
     * @return string Extracted plain text.
     */
    public static function extract_text_from_path(
        string $filepath,
        string $filename,
        string $mimetype = '',
        string $plugin = 'search_typesense'
    ): string {
        if (!is_readable($filepath)) {
            return '';
        }
        $size = @filesize($filepath);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_SIZE_BYTES) {
            return '';
        }

        $ext = \core_text::strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $text = '';

        if ($ext === 'docx' || $mimetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            $text = self::extract_docx($filepath);
        } else if ($ext === 'pptx' || $mimetype === 'application/vnd.openxmlformats-officedocument.presentationml.presentation') {
            $text = self::extract_pptx($filepath);
        } else if ($ext === 'xlsx' || $mimetype === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            $text = self::extract_xlsx($filepath);
        } else if (in_array($ext, ['odt', 'odp', 'ods'], true) || strpos($mimetype, 'application/vnd.oasis.opendocument') === 0) {
            $text = self::extract_opendocument($filepath);
        } else if ($ext === 'pdf' || $mimetype === 'application/pdf') {
            $text = self::extract_pdf($filepath, $plugin);
        } else if (self::is_inline_text_format($ext, $mimetype)) {
            $raw = (string)@file_get_contents($filepath);
            $text = self::extract_text_from_string($raw, $filename, $mimetype, $plugin);
        }

        return self::normalize_extracted_text($text);
    }

    /**
     * Extract plain text from raw file bytes in memory.
     *
     * @param string $content Raw file content.
     * @param string $filename Filename for extension detection.
     * @param string $mimetype Optional MIME type.
     * @param string $plugin Plugin config namespace.
     * @return string Extracted plain text.
     */
    public static function extract_text_from_string(
        string $content,
        string $filename,
        string $mimetype = '',
        string $plugin = 'search_typesense'
    ): string {
        if ($content === '' || strlen($content) > self::MAX_FILE_SIZE_BYTES) {
            return '';
        }

        $ext = \core_text::strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, ['vtt', 'srt'], true) || $mimetype === 'text/vtt') {
            return self::normalize_extracted_text(self::extract_captions($content));
        }

        if (
            in_array($ext, ['html', 'htm', 'xhtml', 'xml'], true) ||
                in_array($mimetype, ['text/html', 'application/xhtml+xml', 'application/xml', 'text/xml'], true)
        ) {
            return self::normalize_extracted_text(self::strip_html_or_xml($content));
        }

        if (in_array($ext, ['txt', 'md', 'markdown', 'csv', 'tsv', 'json'], true) || strpos($mimetype, 'text/') === 0) {
            return self::normalize_extracted_text($content);
        }

        // Binary formats passed as strings: write to temp file and delegate.
        if (in_array($ext, ['docx', 'pptx', 'xlsx', 'odt', 'odp', 'ods', 'pdf'], true)) {
            $tempdir = make_request_directory();
            $temppath = $tempdir . '/extract_' . uniqid('', true) . '.' . $ext;
            if (@file_put_contents($temppath, $content) === false) {
                return '';
            }
            try {
                return self::extract_text_from_path($temppath, $filename, $mimetype, $plugin);
            } finally {
                @unlink($temppath);
            }
        }

        return '';
    }

    /**
     * Check whether a file extension or MIME type is supported for text extraction.
     *
     * @param string $ext Lowercase file extension.
     * @param string $mimetype File MIME type.
     * @return bool
     */
    public static function is_supported_extension(string $ext, string $mimetype = ''): bool {
        $supportedexts = [
            'pdf', 'docx', 'pptx', 'xlsx', 'odt', 'odp', 'ods',
            'txt', 'md', 'markdown', 'csv', 'tsv', 'html', 'htm', 'xhtml', 'xml', 'vtt', 'srt', 'json',
        ];
        if (in_array($ext, $supportedexts, true)) {
            return true;
        }
        if (strpos($mimetype, 'text/') === 0) {
            return true;
        }
        $supportedmimes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.presentation',
            'application/vnd.oasis.opendocument.spreadsheet',
        ];
        return in_array($mimetype, $supportedmimes, true);
    }

    /**
     * Whether a format can be parsed directly from an in-memory string without ZipArchive/CLI tools.
     *
     * @param string $ext Lowercase file extension.
     * @param string $mimetype File MIME type.
     * @return bool
     */
    protected static function is_inline_text_format(string $ext, string $mimetype): bool {
        $inlineexts = ['txt', 'md', 'markdown', 'csv', 'tsv', 'html', 'htm', 'xhtml', 'xml', 'vtt', 'srt', 'json'];
        if (in_array($ext, $inlineexts, true)) {
            return true;
        }
        return strpos($mimetype, 'text/') === 0;
    }

    /**
     * Extract text from a Word (.docx) archive.
     *
     * @param string $filepath Path to .docx file.
     * @return string
     */
    protected static function extract_docx(string $filepath): string {
        if (!class_exists('\ZipArchive')) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            return '';
        }
        $parts = [];
        $candidates = ['word/document.xml', 'word/header1.xml', 'word/footer1.xml', 'word/footnotes.xml', 'word/endnotes.xml'];
        foreach ($candidates as $entry) {
            $xml = $zip->getFromName($entry);
            if (is_string($xml) && $xml !== '') {
                $parts[] = self::strip_html_or_xml($xml);
            }
        }
        $zip->close();
        return implode("\n", $parts);
    }

    /**
     * Extract text from a PowerPoint (.pptx) archive across all slides and speaker notes.
     *
     * @param string $filepath Path to .pptx file.
     * @return string
     */
    protected static function extract_pptx(string $filepath): string {
        if (!class_exists('\ZipArchive')) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            return '';
        }
        $slides = [];
        $notes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slides[(int)$m[1]] = $name;
            } else if (preg_match('#^ppt/notesSlides/notesSlide(\d+)\.xml$#', $name, $m)) {
                $notes[(int)$m[1]] = $name;
            }
        }
        ksort($slides);
        ksort($notes);

        $parts = [];
        foreach ($slides as $entry) {
            $xml = $zip->getFromName($entry);
            if (is_string($xml) && $xml !== '') {
                $parts[] = self::strip_html_or_xml($xml);
            }
        }
        foreach ($notes as $entry) {
            $xml = $zip->getFromName($entry);
            if (is_string($xml) && $xml !== '') {
                $parts[] = self::strip_html_or_xml($xml);
            }
        }
        $zip->close();
        return implode("\n", $parts);
    }

    /**
     * Extract shared strings from an Excel (.xlsx) workbook.
     *
     * @param string $filepath Path to .xlsx file.
     * @return string
     */
    protected static function extract_xlsx(string $filepath): string {
        if (!class_exists('\ZipArchive')) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            return '';
        }
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return '';
        }
        return self::strip_html_or_xml($xml);
    }

    /**
     * Extract text from an OpenDocument (.odt, .odp, .ods) archive.
     *
     * @param string $filepath Path to OpenDocument file.
     * @return string
     */
    protected static function extract_opendocument(string $filepath): string {
        if (!class_exists('\ZipArchive')) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            return '';
        }
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return '';
        }
        return self::strip_html_or_xml($xml);
    }

    /**
     * Extract text from a PDF file using the 3-step local extraction chain:
     * 1. pdftotext CLI (poppler-utils)
     * 2. Moodle's built-in Ghostscript ($CFG->pathtogs with -sDEVICE=txtwrite)
     * 3. Built-in pure-PHP FlateDecode and ToUnicode PDF stream text parser
     *
     * @param string $filepath Path to PDF file on disk.
     * @param string $plugin Plugin config namespace.
     * @return string
     */
    protected static function extract_pdf(string $filepath, string $plugin = 'search_typesense'): string {
        // 1. pdftotext binary (poppler-utils) if available.
        $pdftotext = self::resolve_pdftotext_binary($plugin);
        $pdftotextparsed = false;
        if ($pdftotext !== '') {
            $clitext = self::run_pdftotext($pdftotext, $filepath, $pdftotextparsed);
            if ($clitext !== '') {
                return $clitext;
            }
        }

        // 2. Fast in-memory pure-PHP PDF stream extractor before spawning Ghostscript subprocesses.
        $raw = (string)@file_get_contents($filepath);
        if ($raw !== '') {
            $nativetext = self::extract_pdf_text_native($raw);
            unset($raw);
            if ($nativetext !== '') {
                return $nativetext;
            }
        }

        // 3. Only fall back to Ghostscript if pdftotext did not already parse the PDF cleanly.
        if (!$pdftotextparsed) {
            $gstext = self::run_ghostscript_txtwrite($filepath);
            if ($gstext !== '') {
                return $gstext;
            }
        }

        return '';
    }

    /**
     * Resolve an executable pdftotext binary path if installed on the server.
     *
     * @param string $plugin Plugin config namespace.
     * @return string Executable path or empty string.
     */
    public static function resolve_pdftotext_binary(string $plugin = 'search_typesense'): string {
        global $CFG;

        $configured = trim((string)get_config($plugin, 'pathtopdftotext'));
        $cachekey = $plugin . ':' . $configured;
        if (array_key_exists($cachekey, self::$pdftotextcache)) {
            return self::$pdftotextcache[$cachekey];
        }

        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        if (!empty($CFG->pathtopdftoppm)) {
            $candidates[] = dirname((string)$CFG->pathtopdftoppm) . '/pdftotext';
        }
        $candidates[] = '/usr/bin/pdftotext';
        $candidates[] = '/usr/local/bin/pdftotext';
        $candidates[] = '/opt/homebrew/bin/pdftotext';

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && @is_file($candidate) && @is_executable($candidate)) {
                self::$pdftotextcache[$cachekey] = $candidate;
                return $candidate;
            }
        }
        self::$pdftotextcache[$cachekey] = '';
        return '';
    }

    /**
     * Run pdftotext on a PDF file and return UTF-8 stdout.
     *
     * @param string $binary Validated pdftotext executable path.
     * @param string $filepath Path to PDF file.
     * @param bool $parsedcleanly Set to true when pdftotext exits with status 0.
     * @return string
     */
    protected static function run_pdftotext(string $binary, string $filepath, bool &$parsedcleanly = false): string {
        $parsedcleanly = false;
        if (!function_exists('exec')) {
            return '';
        }
        $cmd = escapeshellcmd($binary)
            . ' -q -enc UTF-8 -nopgbrk -l 100 '
            . escapeshellarg($filepath)
            . ' - 2>/dev/null';
        $output = [];
        $returnvar = 0;
        @exec($cmd, $output, $returnvar);
        if ($returnvar === 0) {
            $parsedcleanly = true;
        }
        if ($returnvar !== 0 || empty($output)) {
            return '';
        }
        return trim(implode("\n", $output));
    }

    /**
     * Run Moodle's configured Ghostscript binary ($CFG->pathtogs) using -sDEVICE=txtwrite.
     *
     * @param string $filepath Path to PDF file.
     * @return string
     */
    protected static function run_ghostscript_txtwrite(string $filepath): string {
        global $CFG;
        if (empty($CFG->pathtogs) || !function_exists('exec')) {
            return '';
        }
        $gs = trim((string)$CFG->pathtogs);
        if ($gs === '' || !@is_file($gs) || !@is_executable($gs)) {
            return '';
        }
        $cmd = escapeshellcmd($gs)
            . ' -sDEVICE=txtwrite -q -dNOPAUSE -dBATCH -dSAFER -dLastPage=25 -sOutputFile=- '
            . escapeshellarg($filepath)
            . ' 2>/dev/null';
        $output = [];
        $returnvar = 0;
        @exec($cmd, $output, $returnvar);
        if ($returnvar !== 0 || empty($output)) {
            return '';
        }
        return trim(implode("\n", $output));
    }

    /**
     * Pure-PHP PDF text stream parser for standard FlateDecode, CID/ToUnicode, and uncompressed PDFs.
     *
     * @param string $raw Raw PDF file bytes.
     * @return string Extracted plain text.
     */
    public static function extract_pdf_text_native(string $raw): string {
        if ($raw === '' || strpos($raw, '%PDF') === false) {
            return '';
        }

        $inflatedstreams = [];
        $globalcmap = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = self::inflate_pdf_stream($stream);
                if ($decoded === '') {
                    continue;
                }
                if (strpos($decoded, 'begincmap') !== false) {
                    $cmap = self::parse_tounicode_cmap($decoded);
                    foreach ($cmap as $cid => $utf8) {
                        if (!isset($globalcmap[$cid])) {
                            $globalcmap[$cid] = $utf8;
                        }
                    }
                    continue;
                }
                $inflatedstreams[] = $decoded;
            }
        }

        if (empty($inflatedstreams)) {
            return '';
        }

        return trim(self::parse_pdf_text_operators(implode("\n", $inflatedstreams), $globalcmap));
    }

    /**
     * Attempt to decompress a PDF stream or return it as-is if already plain text operators.
     *
     * @param string $stream Raw stream bytes.
     * @return string
     */
    protected static function inflate_pdf_stream(string $stream): string {
        $trimmed = ltrim($stream, "\r\n");
        if ($trimmed === '') {
            return '';
        }

        $inflated = @gzuncompress($trimmed);
        if (is_string($inflated) && $inflated !== '' && strpos($inflated, "\x00") === false) {
            return $inflated;
        }
        $inflated = @gzinflate($trimmed);
        if (is_string($inflated) && $inflated !== '' && strpos($inflated, "\x00") === false) {
            return $inflated;
        }

        // Uncompressed ASCII content stream containing PDF text operators or CMap definitions.
        if (
            strpos($trimmed, "\x00") === false && (
                strpos($trimmed, 'BT') !== false ||
                strpos($trimmed, 'Tj') !== false ||
                strpos($trimmed, 'TJ') !== false ||
                strpos($trimmed, 'begincmap') !== false)
        ) {
            return $trimmed;
        }

        return '';
    }

    /**
     * Parse a PDF ToUnicode CMap stream (beginbfchar and beginbfrange) into a CID-to-UTF8 lookup table.
     *
     * @param string $cmapstream Decoded CMap stream text.
     * @return array Map of integer glyph ID to UTF-8 character(s).
     */
    protected static function parse_tounicode_cmap(string $cmapstream): array {
        $map = [];

        if (preg_match_all('/beginbfchar\s*(.*?)\s*endbfchar/s', $cmapstream, $charblocks)) {
            foreach ($charblocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $src = (int)hexdec($pair[1]);
                        $map[$src] = self::hex_to_utf8($pair[2]);
                    }
                }
            }
        }

        if (preg_match_all('/beginbfrange\s*(.*?)\s*endbfrange/s', $cmapstream, $rangeblocks)) {
            foreach ($rangeblocks[1] as $block) {
                $pattern = '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]+)>|\[(.*?)\])/s';
                if (preg_match_all($pattern, $block, $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $r) {
                        $start = (int)hexdec($r[1]);
                        $end = (int)hexdec($r[2]);
                        if ($end < $start || ($end - $start) > 1024) {
                            continue;
                        }
                        if (!empty($r[3])) {
                            $base = (int)hexdec($r[3]);
                            for ($cid = $start; $cid <= $end; $cid++) {
                                $cp = $base + ($cid - $start);
                                $map[$cid] = self::codepoint_to_utf8($cp);
                            }
                        } else if (isset($r[4]) && $r[4] !== '') {
                            if (preg_match_all('/<([0-9A-Fa-f]+)>/', $r[4], $targets)) {
                                foreach ($targets[1] as $offset => $hexval) {
                                    $cid = $start + $offset;
                                    if ($cid <= $end) {
                                        $map[$cid] = self::hex_to_utf8($hexval);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Convert a UTF-16BE hex string (e.g. "0041" or "0066006C" ligature) to UTF-8.
     *
     * @param string $hex Hexadecimal string from ToUnicode CMap.
     * @return string
     */
    protected static function hex_to_utf8(string $hex): string {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) <= 4) {
            return self::codepoint_to_utf8((int)hexdec($hex));
        }
        $out = '';
        foreach (str_split($hex, 4) as $chunk) {
            $out .= self::codepoint_to_utf8((int)hexdec($chunk));
        }
        return $out;
    }

    /**
     * Convert a Unicode codepoint integer into a UTF-8 character.
     *
     * @param int $cp Unicode codepoint.
     * @return string
     */
    protected static function codepoint_to_utf8(int $cp): string {
        if ($cp <= 0 || $cp === 0xFEFF) {
            return '';
        }
        if ($cp < 0x20 && $cp !== 0x09 && $cp !== 0x0A && $cp !== 0x0D) {
            return ' ';
        }
        if ($cp === 0xA0) {
            return ' ';
        }
        if (function_exists('mb_chr')) {
            $ch = @mb_chr($cp, 'UTF-8');
            if (is_string($ch)) {
                return $ch;
            }
        }
        return html_entity_decode('&#' . $cp . ';', ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * Decode a PDF hex string token (<0036> or <00360033>) using the ToUnicode CMap or UTF-16BE/ASCII fallback.
     *
     * @param string $hex Interior of a <...> PDF hex string.
     * @param array $cmap CID-to-UTF8 lookup table.
     * @return string
     */
    protected static function decode_pdf_hex_string(string $hex, array $cmap): string {
        $clean = preg_replace('/\s+/', '', $hex);
        if ($clean === '') {
            return '';
        }

        // Check for UTF-16BE BOM (FEFF).
        if (stripos($clean, 'FEFF') === 0) {
            return self::hex_to_utf8(substr($clean, 4));
        }

        $out = '';
        if (!empty($cmap) && strlen($clean) % 4 === 0) {
            foreach (str_split($clean, 4) as $chunk) {
                $cid = (int)hexdec($chunk);
                if (isset($cmap[$cid])) {
                    $out .= $cmap[$cid];
                } else if ($cid >= 32 && $cid <= 126) {
                    $out .= chr($cid);
                }
            }
            return $out;
        }

        if (!empty($cmap) && strlen($clean) % 2 === 0) {
            foreach (str_split($clean, 2) as $chunk) {
                $cid = (int)hexdec($chunk);
                if (isset($cmap[$cid])) {
                    $out .= $cmap[$cid];
                } else if ($cid >= 32 && $cid <= 126) {
                    $out .= chr($cid);
                }
            }
            return $out;
        }

        foreach (str_split($clean, 2) as $bytehex) {
            $code = (int)hexdec($bytehex);
            if ($code >= 32 && $code <= 126) {
                $out .= chr($code);
            }
        }
        return $out;
    }

    /**
     * Parse PDF text showing operators (Tj, TJ, ', ") from a decoded content stream.
     *
     * @param string $content Decoded PDF content stream.
     * @param array $cmap Optional ToUnicode CMap lookup table.
     * @return string
     */
    protected static function parse_pdf_text_operators(string $content, array $cmap = []): string {
        $blocks = [];
        if (preg_match_all('/BT\b(.*?)\bET/s', $content, $btmatches)) {
            $blocks = $btmatches[1];
        } else if (strpos($content, 'Tj') !== false || strpos($content, 'TJ') !== false) {
            $blocks = [$content];
        }

        $out = [];
        foreach ($blocks as $block) {
            $tokens = [];
            // Match [...] TJ arrays, (...) Tj/'/" literal strings, or <...> Tj hex strings.
            $pattern = '/\[((?:[^\[\]]|\((?:\\\\.|[^\\\\()])*\))*)\]\s*TJ'
                . '|\(((?:\\\\.|[^\\\\()])*)\)\s*(?:Tj|\'|")'
                . '|<([0-9A-Fa-f\s]+)>\s*(?:Tj|\'|")/s';
            if (preg_match_all($pattern, $block, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    if (isset($match[1]) && $match[1] !== '') {
                        $arraybody = $match[1];
                        $subpattern = '/\(((?:\\\\.|[^\\\\()])*)\)|<([0-9A-Fa-f\s]+)>|(-?\d+(?:\.\d+)?)/s';
                        if (preg_match_all($subpattern, $arraybody, $sub, PREG_SET_ORDER)) {
                            $word = '';
                            foreach ($sub as $item) {
                                if (isset($item[3]) && $item[3] !== '') {
                                    if ((float)$item[3] < -150) {
                                        $word .= ' ';
                                    }
                                } else if (isset($item[2]) && $item[2] !== '') {
                                    $word .= self::decode_pdf_hex_string($item[2], $cmap);
                                } else if (isset($item[1]) && $item[1] !== '') {
                                    $word .= self::decode_pdf_literal($item[1]);
                                }
                            }
                            if (trim($word) !== '') {
                                $tokens[] = trim(preg_replace('/\s+/', ' ', $word));
                            }
                        }
                    } else if (isset($match[2]) && $match[2] !== '') {
                        $decoded = trim(self::decode_pdf_literal($match[2]));
                        if ($decoded !== '') {
                            $tokens[] = $decoded;
                        }
                    } else if (isset($match[3]) && $match[3] !== '') {
                        $decoded = trim(self::decode_pdf_hex_string($match[3], $cmap));
                        if ($decoded !== '') {
                            $tokens[] = $decoded;
                        }
                    }
                }
            }
            if (!empty($tokens)) {
                $out[] = implode(' ', $tokens);
            }
        }

        return implode("\n", $out);
    }

    /**
     * Decode a PDF literal string's escape sequences (\n, \r, \t, \(, \), \\, and \ddd octal codes).
     *
     * @param string $literal Raw interior of a PDF (...) string literal.
     * @return string
     */
    protected static function decode_pdf_literal(string $literal): string {
        // Remove line-continuation backslashes first.
        $literal = preg_replace('/\\\\\r?\n/', '', $literal);

        return preg_replace_callback('/\\\\([0-7]{1,3}|[nrtbf()\\\\])/', function (array $m): string {
            $esc = $m[1];
            switch ($esc) {
                case 'n':
                    return "\n";
                case 'r':
                    return "\r";
                case 't':
                    return "\t";
                case 'b':
                    return '';
                case 'f':
                    return ' ';
                case '(':
                    return '(';
                case ')':
                    return ')';
                case '\\':
                    return '\\';
                default:
                    $code = octdec($esc);
                    return ($code >= 32 && $code <= 126) ? chr($code) : ' ';
            }
        }, $literal);
    }

    /**
     * Strip WebVTT / SRT cue headers and timestamps to leave clean spoken transcript text.
     *
     * @param string $raw Raw .vtt or .srt content.
     * @return string
     */
    protected static function extract_captions(string $raw): string {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed === 'WEBVTT' || strpos($trimmed, 'WEBVTT ') === 0) {
                continue;
            }
            if (preg_match('/^\d+$/', $trimmed)) {
                continue;
            }
            if (strpos($trimmed, '-->') !== false) {
                continue;
            }
            if (strpos($trimmed, 'NOTE ') === 0 || $trimmed === 'NOTE') {
                continue;
            }
            $kept[] = strip_tags($trimmed);
        }
        return implode(' ', $kept);
    }

    /**
     * Convert XML/HTML into space-separated plain text without concatenating adjacent block tags.
     *
     * @param string $markup Raw XML or HTML string.
     * @return string
     */
    protected static function strip_html_or_xml(string $markup): string {
        $clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $markup);
        // Insert spaces before closing block/paragraph/cell tags and self-closing breaks/tabs.
        $clean = preg_replace('#<(?:w:br|w:tab|br|hr)\b[^>]*/?>#i', ' ', (string)$clean);
        $clean = preg_replace('#</(?:w:p|w:tr|w:tc|a:p|text:p|text:h|si|p|div|li|tr|td|th|h[1-6])\s*>#i', "\n", (string)$clean);
        $stripped = strip_tags((string)$clean);
        return html_entity_decode($stripped, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Clean UTF-8, collapse whitespace, and cap length for a single extracted file.
     *
     * @param string $text Raw extracted text.
     * @return string
     */
    protected static function normalize_extracted_text(string $text): string {
        if ($text === '') {
            return '';
        }
        $clean = fix_utf8($text);
        if (function_exists('iconv')) {
            $iconv = @iconv('UTF-8', 'UTF-8//IGNORE', $clean);
            if (is_string($iconv) && $iconv !== '') {
                $clean = $iconv;
            }
        }
        // Remove control characters except newline/tab.
        $clean = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', (string)$clean);
        // Collapse horizontal whitespace.
        $clean = (string)preg_replace('/[ \t]+/', ' ', $clean);
        // Collapse excessive blank lines.
        $clean = (string)preg_replace('/\n{3,}/', "\n\n", $clean);
        $clean = trim($clean);
        if (\core_text::strlen($clean) > self::MAX_CHARS_PER_FILE) {
            $clean = \core_text::substr($clean, 0, self::MAX_CHARS_PER_FILE);
        }
        return trim($clean);
    }
}
