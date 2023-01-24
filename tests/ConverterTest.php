<?php

namespace GoReply\AnsiToHtml;

use GoReply\AnsiToHtml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\BufferedOutput;

class ConverterTest extends TestCase
{
    /**
     * @dataProvider getConvertData
     */
    public function testConvert(string $input, string $expected)
    {
        $converter = new AnsiToHtml\Converter();
        $this->assertEquals($expected, $converter->convert($input));
    }

    public function getConvertData()
    {
        yield 'text is escaped' => ['foo <br>', <<<'HTML'
        foo &lt;br&gt;
        HTML
        ];

        yield 'newlines are preserved' => ["foo\nbar", "foo\nbar"];

        yield 'backspaces 1' => ["foobar\x08\x08\x08   ", 'foo   '];

        yield 'backspaces 2' => ["foob\e[31;41ma\e[0mr\x08\x08\x08   ", 'foo   '];

        yield 'backspaces 3' => ["hi\x08\x08m", 'mi'];

        yield 'basic color' => [
            "\e[31;41mfoo\e[0m",
            <<<'HTML'
            <span style="color:#a00;background-color:#a00">foo</span>
            HTML
        ];

        yield 'bold (bright)' => [
            "\e[31;41;1mfoo\e[0m",
            <<<'HTML'
            <span style="color:#a00;background-color:#a00;font-weight:bold">foo</span>
            HTML
        ];

        yield 'unclosed' => [
            "\e[1mfoo",
            <<<'HTML'
            <span style="font-weight:bold">foo</span>
            HTML
        ];

        yield 'carriage returns' => ["foo\rbar\rbarfoo", 'barfoo'];

        yield 'underline' => [
            "\e[4mfoo\e[0m",
            <<<'HTML'
            <span style="text-decoration:underline">foo</span>
            HTML
        ];

        yield 'sRGB colors' => [
            "\e[38;5;208mfoo",
            <<<'HTML'
            <span style="color:#d45500">foo</span>
            HTML
        ];

        yield 'stacking' => [
            "\e[38;5;208mfoo\e[38;5;113;1mbar\e[m",
            <<<'HTML'
            <span style="color:#d45500">foo</span><span style="color:#55aa2a;font-weight:bold">bar</span>
            HTML
        ];

        yield 'contrived escape codes' => [
            "ho\e[1mi\rh\e[mi\ni\nh\x08i!\x08\x08\x08x",
            <<<'HTML'
            <span style="font-weight:bold">h</span>i<span style="font-weight:bold">i</span>
            i
            x!
            HTML
        ];

        yield 'cursor movement' => ["\e[1mHi mom!\x0D\e[m\x1B[2KActual output", 'Actual output'];

        yield 'erase line' => [
            "12345\x08\x08\e[0K\n12345\x08\x08\e[1K\n12345\x08\x08\e[2K\n12345\x08\x08\e[K",
            "123  \n    5\n     \n123  ",
        ];

        yield 'symfony progress bar' => [
            (static function () {
                $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
                $progress = new ProgressBar($output, 50);
                $progress->start();

                $i = 0;
                while ($i++ < 50) {
                    $progress->advance();
                }

                $progress->finish();

                return $output->fetch();
            })(),
            '  50/50 [============================] 100%',
        ];

        yield 'hyperlink' => [
            "\e]8;;https://example.com\e\\This is a link\e]8;;\e\\ and this is not",
            '<a href="https://example.com">This is a link</a> and this is not',
        ];

        yield 'hyperlink with mixed colors' => [
            "\e]8;;https://example.com\e\\This is a \e[31mlink\e]8;;\e\\ and this is not",
            '<a href="https://example.com">This is a </a><a href="https://example.com" style="color:#a00">link</a><span style="color:#a00"> and this is not</span>',
        ];
    }
}
