<?php

namespace GoReply\AnsiToHtml;

/**
 * @internal
 */
final class NodeStyle
{
    /** @var string[][] */
    private $styles;
    /** @var array */
    private $attrs;

    /** @var self */
    private static $empty;

    /**
     * @param string[][] $styles
     */
    public function __construct(array $attrs, array $styles)
    {
        $this->attrs = $attrs;
        $this->styles = $styles;
    }

    public function withAttr(string $attr, string $value): self
    {
        if (($this->attrs[$attr] ?? null) === $value) {
            return $this;
        }

        $attrs = $this->attrs;
        if ($value === null || $value === '') {
            unset($attrs[$attr]);
        } else {
            $attrs[$attr] = $value;
        }

        return new self($attrs, $this->styles);
    }

    public function withStyle(string $property, string $value): self
    {
        if (isset($this->styles[$property][$value])) {
            return $this;
        }

        $styles = $this->styles;
        if ($property === 'text-decoration') {
            $styles[$property][$value] = $value;
        } else {
            $styles[$property] = [$value => $value];
        }

        return new self($this->attrs, $styles);
    }

    public function isEmpty(): bool
    {
        return !$this->attrs && !$this->styles;
    }

    public function getOpenString(): string
    {
        $tag = isset($this->attrs['href']) ? 'a' : 'span';

        $style = [];
        foreach ($this->styles as $attr => $values) {
            $value = \implode(' ', $values);
            $style[] = $attr . ':' . $value;
        }

        $attrs = $this->attrs;
        $attrs['style'] = \implode(';', $style) ?: null;

        $result = '<' . $tag;

        foreach ($attrs as $attr => $value) {
            if ($value !== null) {
                $result .= ' ' . $attr . '="' . \htmlspecialchars($value) . '"';
            }
        }

        return $result . '>';
    }

    public function getCloseString(): string
    {
        $tag = isset($this->attrs['href']) ? 'a' : 'span';

        return '</' . $tag . '>';
    }

    /**
     * @param mixed $other
     */
    public function equals($other): bool
    {
        return $other instanceof self && $other->attrs === $this->attrs && $other->styles === $this->styles;
    }

    public static function empty(): self
    {
        if (!self::$empty) {
            self::$empty = new self([], []);
        }

        return self::$empty;
    }
}
