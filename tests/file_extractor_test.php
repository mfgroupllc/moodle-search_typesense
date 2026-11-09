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
 * Unit tests for attached document and file content extraction.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

/**
 * Unit tests for attached document and file content extraction.
 *
 * @package    search_typesense
 * @copyright  2026 Chris Murad
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \search_typesense\file_extractor
 */
final class file_extractor_test extends \advanced_testcase {

    /**
     * Test Word (.docx) XML extraction via ZipArchive.
     */
    public function test_extract_docx(): void {
        $tempdir = make_request_directory();
        $docxpath = $tempdir . '/syllabus.docx';

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($docxpath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>'
            . '<w:p><w:r><w:t>Course Syllabus: Introduction to Distributed Systems</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Grading rubric &amp; weekly reading schedule.</w:t></w:r></w:p>'
            . '</w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $text = file_extractor::extract_text_from_path($docxpath, 'syllabus.docx');
        $this->assertStringContainsString('Course Syllabus: Introduction to Distributed Systems', $text);
        $this->assertStringContainsString('Grading rubric & weekly reading schedule.', $text);
    }

    /**
     * Test PowerPoint (.pptx) multi-slide and speaker notes extraction.
     */
    public function test_extract_pptx(): void {
        $tempdir = make_request_directory();
        $pptxpath = $tempdir . '/lecture01.pptx';

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pptxpath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString(
            'ppt/slides/slide1.xml',
            '<p:sld><a:p><a:r><a:t>Slide One Title</a:t></a:r></a:p></p:sld>'
        );
        $zip->addFromString(
            'ppt/slides/slide2.xml',
            '<p:sld><a:p><a:r><a:t>Consensus Algorithms Overview</a:t></a:r></a:p></p:sld>'
        );
        $zip->addFromString(
            'ppt/notesSlides/notesSlide1.xml',
            '<p:notes><a:p><a:r><a:t>Instructor speaker notes for module one</a:t></a:r></a:p></p:notes>'
        );
        $zip->close();

        $text = file_extractor::extract_text_from_path($pptxpath, 'lecture01.pptx');
        $this->assertStringContainsString('Slide One Title', $text);
        $this->assertStringContainsString('Consensus Algorithms Overview', $text);
        $this->assertStringContainsString('Instructor speaker notes for module one', $text);
    }

    /**
     * Test WebVTT (.vtt) caption extraction strips cue headers and timestamps.
     */
    public function test_extract_vtt_captions(): void {
        $vtt = "WEBVTT\n\n1\n00:00:01.000 --> 00:00:04.500\nWelcome to module three on database indexing.\n\n"
            . "2\n00:00:05.000 --> 00:00:09.000\n<v Instructor>Today we compare B-trees and inverted indexes.</v>\n";

        $text = file_extractor::extract_text_from_string($vtt, 'captions.vtt', 'text/vtt');
        $this->assertStringNotContainsString('WEBVTT', $text);
        $this->assertStringNotContainsString('-->', $text);
        $this->assertStringContainsString('Welcome to module three on database indexing.', $text);
        $this->assertStringContainsString('Today we compare B-trees and inverted indexes.', $text);
    }

    /**
     * Test pure-PHP FlateDecode PDF stream text extraction.
     */
    public function test_extract_pdf_text_native(): void {
        $streamcontent = "BT\n/F1 12 Tf\n72 720 Td\n(biochemistry laboratory safety protocol) Tj\n"
            . "0 -18 Td\n[(Wear ) -180 (protective ) -160 (eyewear)] TJ\nET";
        $compressed = gzcompress($streamcontent);
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Length " . strlen($compressed) . " /Filter /FlateDecode >>\n"
            . "stream\n" . $compressed . "\nendstream\nendobj\n%%EOF";

        $text = file_extractor::extract_pdf_text_native($pdf);
        $this->assertStringContainsString('biochemistry laboratory safety protocol', $text);
        $this->assertStringContainsString('Wear protective eyewear', $text);
    }

    /**
     * Test pure-PHP PDF ToUnicode CMap + hex glyph string (<0024>...) extraction.
     */
    public function test_extract_pdf_text_native_tounicode_cmap(): void {
        $cmap = "/CIDInit /ProcSet findresource begin\nbegincmap\n"
            . "2 beginbfchar\n<0003> <0020>\n<0010> <004D>\nendbfchar\n"
            . "1 beginbfrange\n<0044> <0048> <0061>\nendbfrange\nendcmap";
        $content = "BT /C2_0 12 Tf [<0010>-120 <0044>-120 <0047>-120 <0048>-180 <0003>-120 <0046>-120 <0044>-120 <0045>] TJ ET";

        $pdf = "%PDF-1.4\n"
            . "1 0 obj\n<< /Filter /FlateDecode >>\nstream\n" . gzcompress($cmap) . "\nendstream\nendobj\n"
            . "2 0 obj\n<< /Filter /FlateDecode >>\nstream\n" . gzcompress($content) . "\nendstream\nendobj\n%%EOF";

        $text = file_extractor::extract_pdf_text_native($pdf);
        $this->assertStringContainsString('Made cab', $text);
    }
}
