<?php

namespace Deguif\Icu;

final class TokenStream
{
    private $tokens = [];
    private $source;

    public function __construct(array $tokens, string $source)
    {
        $this->tokens = $tokens;
        $this->source = $source;
    }

    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
