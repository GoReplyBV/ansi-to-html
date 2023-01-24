<?php

namespace GoReply\AnsiToHtml;

/**
 * @internal
 */
final class Node
{
    /** @var string */
    private $text = '';

    /** @var NodeStyle */
    private $style;

    public function __construct(string $text, ?NodeStyle $style = null)
    {
        $this->text = $text;
        $this->style = $style ?? NodeStyle::empty();
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getStyle(): NodeStyle
    {
        return $this->style;
    }

    /**
     * @param mixed $other
     */
    public function equals($other): bool
    {
        return $other === $this;
    }
}
