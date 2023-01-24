<?php

namespace GoReply\AnsiToHtml;

use function array_map;
use function array_shift;
use function explode;
use function htmlspecialchars;
use function max;
use function preg_match_all;
use function sprintf;
use const PREG_SET_ORDER;

class Converter
{
    /** @var string[] */
    private static $colors;

    /** @var NodeStyle */
    private $activeStyle;

    /** @var Node[][] */
    private $nodes = [];

    /** @var int */
    private $line = 0;

    /** @var int */
    private $column = 0;

    /** @var int */
    private $storedLine = 0;

    /** @var int */
    private $storedColumn = 0;

    public function convert(string $text): string
    {
        if (self::$colors === null) {
            self::$colors = self::buildColorMap();
        }

        $this->activeStyle = NodeStyle::empty();
        $this->nodes = [];
        $this->storedLine = $this->line = 0;
        $this->storedColumn = $this->column = 0;

        foreach ($this->tokenize($text) as $token) {
            [$token, $data] = $token;

            if ($token === 'text') {
                $this->handleText($data);
            } elseif ($token === 'control') {
                [$type, $params] = $data;
                $this->handleControlSequence($type, $params);
            } elseif ($token === 'osc') {
                [$type, $params] = $data;
                $this->handleOperatingSystemCommand($type, $params);
            }
        }

        return $this->generateOutput();
    }

    private function tokenize(string $text): iterable
    {
        $text = htmlspecialchars($text);

        preg_match_all(
            <<<'REGEXP'
            /
              \e \[ (?<csi_params> [\d;]* ) (?<csi_type> [ABCDEFGHJKSTfmsu] )
            | \e \] (?<osc> 8 ) ; (?<osc_params> (?: (?! \e\\ ) . )* ) \e\\
            | (?<text> &[^;]+; | . )
            /usx
            REGEXP,
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            if (isset($match['text'])) {
                yield ['text', $match['text']];
            } elseif (isset($match['osc'])) {
                yield ['osc', [$match['osc'], $match['osc_params']]];
            } else {
                $params = array_map('intval', explode(';', $match['csi_params']));
                yield ['control', [$match['csi_type'], $params]];
            }
        }
    }

    private function generateOutput(): string
    {
        /** @var NodeStyle|null $prevStyle */
        $prevStyle = null;
        $output = '';
        foreach ($this->nodes as $ii => $line) {
            if ($ii) {
                $output .= "\n";
            }

            foreach ($line as $jj => $column) {
                $style = $column->getStyle();
                if (!$style->isEmpty() && !$style->equals($prevStyle)) {
                    $output .= $style->getOpenString();
                }

                $output .= $column->getText();

                if (!$style->isEmpty()) {
                    $next = $line[$jj + 1] ?? $this->nodes[$ii + 1][0] ?? null;
                    if (!$next || !$style->equals($next->getStyle())) {
                        $output .= $style->getCloseString();
                    }
                }

                $prevStyle = $style;
            }
        }

        return $output;
    }

    private function handleText(string $data): void
    {
        switch ($data) {
            case "\x08":
                if ($this->column > 0) {
                    --$this->column;
                }
                break;
            case "\r":
                $this->column = 0;
                break;
            case "\n":
                $this->line++;
                $this->column = 0;
                break;
            default:
                $this->nodes[$this->line][$this->column] = new Node($data, $this->activeStyle);
                ++$this->column;
        }
    }

    /**
     * @param int[] $params
     */
    private function handleControlSequence(string $type, array $params): void
    {
        switch ($type) {
            case 'm':
                $this->setSgrParameters($params);
                break;
            case 'A':
                $this->line = max(0, $this->line - ($params[0] ?? 1));
                break;
            case 'B':
                $this->line += $params[0] ?? 1;
                break;
            case 'C':
                $this->column += $params[0] ?? 1;
                break;
            case 'D':
                $this->column = max(0, $this->column - ($params[0] ?? 1));
                break;
            case 'E':
                $this->line += $params[0] ?? 1;
                $this->column = 0;
                break;
            case 'F':
                $this->line = max(0, $this->line - ($params[0] ?? 1));
                $this->column = 0;
                break;
            case 'G':
                $this->column = $params[0] ?? 0;
                break;
            case 'H':
            case 'f':
                $this->line = max(0, ($params[0] ?? 1) - 1);
                $this->column = max(0, ($params[1] ?? 1) - 1);
                break;
            case 'K':
                switch ($params[0] ?? 0) {
                    case 1:
                        $min = 0;
                        $max = $this->column;
                        break;
                    case 2:
                        $min = 0;
                        $max = array_key_last($this->nodes[$this->line] ?? []);
                        break;
                    case 0:
                    default:
                        $min = $this->column;
                        $max = array_key_last($this->nodes[$this->line] ?? []);
                        break;
                }

                for ($ii = $min; $ii <= $max; ++$ii) {
                    $this->nodes[$this->line][$ii] = new Node(' ');
                }
                break;
            case 's':
                $this->storedLine = $this->line;
                $this->storedColumn = $this->column;
                break;
            case 'u':
                $this->line = $this->storedLine;
                $this->column = $this->storedColumn;
        }
    }

    private function handleOperatingSystemCommand(string $type, string $params)
    {
        switch ($type) {
            case '8':
                [$params, $href] = explode(';', $params, 2);
                $this->pushAttr('href', $href);
                break;
        }
    }

    /**
     * @param int[] $codes
     */
    private function setSgrParameters(array $codes)
    {
        while (null !== $code = array_shift($codes)) {
            switch ($code) {
                case 0:
                    $this->activeStyle = NodeStyle::empty();
                    break;
                case 1:
                    self::pushStyle('font-weight', 'bold');
                    break;
                case 3:
                    self::pushStyle('font-style', 'italic');
                    break;
                case 4:
                    self::pushStyle('text-decoration', 'underline');
                    break;
                case 5:
                case 6:
                case 7:
                    self::pushStyle('text-decoration', 'blink');
                    break;
                case 8:
                    self::pushStyle('display', 'none');
                    break;
                case 9:
                    self::pushStyle('text-decoration', 'line-through');
                    break;
                case 30:
                case 31:
                case 32:
                case 33:
                case 34:
                case 35:
                case 36:
                case 37:
                    $this->pushStyle('color', self::$colors[$code - 30] ?? '');
                    break;
                case 38:
                    if (array_shift($codes) === 5) {
                        $code = array_shift($codes);
                        $this->pushStyle('color', self::$colors[$code] ?? '');
                    }
                    break;
                case 40:
                case 41:
                case 42:
                case 43:
                case 44:
                case 45:
                case 46:
                case 47:
                    $this->pushStyle('background-color', self::$colors[$code - 40] ?? '');
                    break;
                case 48:
                    if (array_shift($codes) === 5) {
                        $code = array_shift($codes);
                        $this->pushStyle('background-color', self::$colors[$code] ?? '');
                    }
                    break;
                case 90:
                case 91:
                case 92:
                case 93:
                case 94:
                case 95:
                case 96:
                case 97:
                    $this->pushStyle('color', self::$colors[8 + ($code - 90)] ?? '');
                    break;
                case 100:
                case 101:
                case 102:
                case 103:
                case 104:
                case 105:
                case 106:
                case 107:
                    $this->pushStyle('background-color', self::$colors[8 + ($code - 100)] ?? '');
                    break;
            }
        }
    }

    private function pushAttr(string $attr, string $value): void
    {
        $this->activeStyle = $this->activeStyle->withAttr($attr, $value);
    }

    private function pushStyle(string $property, string $value): void
    {
        $this->activeStyle = $this->activeStyle->withStyle($property, $value);
    }

    /**
     * @return string[]
     */
    private static function buildColorMap(): array
    {
        $colors = [
            0 => '#000',
            1 => '#a00',
            2 => '#0a0',
            3 => '#a50',
            4 => '#00a',
            5 => '#a0a',
            6 => '#0aa',
            7 => '#aaa',
            8 => '#555',
            9 => '#f55',
            10 => '#5f5',
            11 => '#ff5',
            12 => '#55f',
            13 => '#f5f',
            14 => '#5ff',
            15 => '#fff',
        ];

        for ($red = 0; $red < 6; ++$red) {
            for ($green = 0; $green < 6; ++$green) {
                for ($blue = 0; $blue < 6; ++$blue) {
                    $index = 16 + ($red * 36) + ($green * 6) + $blue;
                    $colors[$index] = self::formatColor(
                        (int)($red * (255 / 6)),
                        (int)($green * (255 / 6)),
                        (int)($blue * (255 / 6))
                    );
                }
            }
        }

        for ($gray = 0; $gray < 24; ++$gray) {
            $index = $gray + 232;
            $colors[$index] = self::formatColor(
                (int)($gray * (255 / 24)),
                (int)($gray * (255 / 24)),
                (int)($gray * (255 / 24))
            );
        }

        return $colors;
    }

    private static function formatColor(int $red, int $green, int $blue): string
    {
        $hex = sprintf('%02x%02x%02x', $red, $green, $blue);
        if ($hex[0] === $hex[1] && $hex[2] === $hex[3] && $hex[4] === $hex[5]) {
            return '#' . $hex[0] . $hex[2] . $hex[4];
        }

        return '#' . $hex;
    }
}
