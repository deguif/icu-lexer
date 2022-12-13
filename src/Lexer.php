<?php

namespace Deguif\Icu;

class Lexer
{
    private $message = '';
    private $messageLength = 0;
    private $tokens = [];

    public function tokenize(string $message): TokenStream
    {
        $this->message = $message;
        $this->messageLength = \mb_strlen($message);
        $this->tokens = [];

        $this->parse(0, 0, 0, Token::ARG_TYPE_NONE);

        return new TokenStream($this->tokens, $this->message);
    }

    private function parse(int $index, int $startLength, int $nestingLevel, ?string $parentType): int
    {
        $this->pushToken(Token::TYPE_MSG_START, $index, $startLength, $nestingLevel);
        $messageStart = \count($this->tokens) - 1;
        $index += $startLength;

        while ($index < $this->messageLength) {
            if ('\'' === $c = \mb_substr($this->message, $index++, 1)) {
                if ($index === $this->messageLength) {
                    $this->needsAutoQuoting = true;
                    $this->pushToken(Token::TYPE_INSERT_CHAR, $index, 0, '\'');
                } else {
                    if ('\'' === $c = \mb_substr($this->message, $index, 1)) {
                        // double apostrophe, skip the second one
                        $this->pushToken(Token::TYPE_SKIP_SYNTAX, $index++, 1, null);
                    } elseif (
                        '{' === $c
                        || '}' === $c
                        || (Token::ARG_TYPE_CHOICE === $parentType && '|' === $c)
                        || (self::hasPluralStyle($parentType) && '#' === $c)
                    ) {
                        // skip the quote-starting apostrophe
                        $this->pushToken(Token::TYPE_SKIP_SYNTAX, $index-1, 1, null);
                        // find the end of the quoted literal text
                        while (true) {
                            if (0 <= $index = \mb_strpos('\'', $index + 1)) {
                                if ('\'' === \mb_substr($this->message, $index + 1, 1)) {
                                    // double apostrophe inside quoted literal text
                                    // still encodes a single apostrophe, skip the second one
                                    $this->pushToken(Token::TYPE_SKIP_SYNTAX, ++$index, 1, null);
                                } else {
                                    // skip the quote-ending apostrophe
                                    $this->pushToken(Token::TYPE_SKIP_SYNTAX, $index++, 1, null);

                                    break;
                                }
                            } else {
                                // The quoted text reaches to the end of the of the message.
                                $index = $this->messageLength;
                                // Add a Part for auto-quoting.
                                $this->pushToken(Token::TYPE_INSERT_CHAR, $index, 0, '\''); // value=char to be inserted
                                $this->needsAutoQuoting = true;

                                break;
                            }
                        }
                    } else {
                        // Interpret the apostrophe as literal text.
                        // Add a Part for auto-quoting.
                        $this->pushToken(Token::TYPE_INSERT_CHAR, $index, 0, '\''); // value=char to be inserted
                        $this->needsAutoQuoting = true;
                    }
                }
            } elseif(self::hasPluralStyle($parentType) && '#' === $c) {
                $this->pushToken(Token::TYPE_REPLACE_NUMBER, $index - 1, 1, null);
            } elseif('{' === $c) {
                $index = $this->parseArgument($index - 1, 1, $nestingLevel);
            } elseif ((0 < $nestingLevel && '}' === $c)
                || (Token::ARG_TYPE_CHOICE === $parentType && '|' === $c)
            ) {
                $limitLength = (Token::ARG_TYPE_CHOICE === $parentType && '}' === $c) ? 0 : 1;
                $this->pushLimitToken($messageStart, Token::TYPE_MSG_LIMIT, $index - 1, $limitLength, $nestingLevel);

                if(Token::ARG_TYPE_CHOICE === $parentType) {
                    // Let the choice style parser see the '}' or '|'.
                    return $index - 1;
                } else {
                    // continue parsing after the '}'
                    return $index;
                }
            }
        }

        if (0 < $nestingLevel && !$this->inTopLevelChoiceMessage($nestingLevel, $parentType)) {
            throw new \RuntimeException('Unmatched braces.');
        }

        $this->pushLimitToken($messageStart, Token::TYPE_MSG_LIMIT, $index, 1, $nestingLevel);

        return $index;
    }

    private function parseChoiceStyle(int $index, int $nestingLevel): int
    {
        $start = $index;
        $index = $this->skipWhitespace($index);

        if ($index === $this->messageLength || '}' === \mb_substr($this->message, $index, 1)) {
            throw new \RuntimeException('Missing choice argument pattern.');
        }

        // See https://github.com/unicode-org/icu/blob/30f737b09d5e9a2940545ac9efb7acce09cd5e3f/icu4c/source/common/messagepattern.cpp#L702
        throw new \RuntimeException('Not implemented');
    }

    private function parseSimpleStyle(int $index): int
    {
        $start = $index;
        $nestedBraces = 0;

        while ($index < $this->messageLength) {
            if ('\'' === $c = \mb_substr($this->message, $index++, 1)) {
                // Treat apostrophe as quoting but include it in the style part.
                // Find the end of the quoted literal text.
                if (false === $index = \mb_strpos($this->message, '\'', $index)) {
                    throw new \RuntimeException('Quoted literal argument style text reaches to the end of the message.');
                }
                // skip the quote-ending apostrophe
                ++$index;
            } elseif ('{' === $c) {
                ++$nestedBraces;
            } elseif ('}' === $c) {
                if(0 < $nestedBraces) {
                    --$nestedBraces;
                } else {
                    $length = --$index - $start;
                    $this->pushToken(Token::TYPE_ARG_STYLE, --$index - $start, $length, null);

                    return $index;
                }
            }
         }

         throw new \RuntimeException('Unmatched \'{\' braces.');
    }

    private function parsePluralOrSelectStyle(string $type, int $index, int $nestingLevel): int
    {
        $isEmpty = true;
        $hasOther = false;

        while (true) {
            $index = $this->skipWhitespace($index);
            $eos = $index === $this->messageLength;

            if($eos || '}' === \mb_substr($this->message, $index, 1)) {
                if($eos === $this->inMessageFormatPattern($nestingLevel)) {
                    throw new \RuntimeException('Pattern syntax error.');
                }

                if(!$hasOther) {
                    throw new \RuntimeException('Missing \'other\' keyword in plural/select pattern.');
                }

                return $index;
            }

            $selectorIndex = $index;

            if (self::hasPluralStyle($type) && '=' === \mb_substr($this->message, $index, 1)) {
                $index = $this->skipDouble($index + 1);
                $length = $index - $selectorIndex;

                if (1 === $length) {
                    throw new \RuntimeException('Bad plural/select pattern syntax.');
                }

                $this->pushToken(Token::TYPE_ARG_SELECTOR, $selectorIndex, $length, \mb_substr($this->message, $selectorIndex, $length));
                $value = $this->parseDouble(\mb_substr($this->message, $selectorIndex + 1, $index - ($selectorIndex + 1)), false);
                $this->pushToken(\is_float($value) ? Token::TYPE_ARG_DOUBLE : Token::TYPE_ARG_INT, $selectorIndex + 1, $index, $value);
            } else {
                $index = $this->skipIdentifier($index);
                $length = $index - $selectorIndex;

                if(0 === $length) {
                    throw new \RuntimeException('Bad plural/select pattern syntax.');
                }

                if (self::hasPluralStyle($type) && 'offset:' === \mb_substr($this->message, $selectorIndex, $length + 1)) {
                    if (!$isEmpty) {
                        throw new \RuntimeException('Plural argument \'offset:\' (if present) must precede key-message pairs.');
                    }

                    $valueIndex = $this->skipWhiteSpace($index + 1);
                    if ($valueIndex === $index = $this->skipDouble($valueIndex)) {
                        throw new \RuntimeException('Missing value for plural \'offset:\'');
                    }

                    $value = $this->parseDouble(\mb_substr($this->message, $valueIndex, $index - $valueIndex), false);
                    $this->pushToken(\is_float($value) ? Token::TYPE_ARG_DOUBLE : Token::TYPE_ARG_INT, $valueIndex, $index - $valueIndex, $value);
                    $isEmpty = false;

                    continue;
                } else {
                    $this->pushToken(Token::TYPE_ARG_SELECTOR, $selectorIndex, $length, $selector = \mb_substr($this->message, $selectorIndex, $length));

                    if('other' === $selector) {
                        $hasOther = true;
                    }
                }
            }

            $index = $this->skipWhiteSpace($index);

            if ($index === $this->messageLength || '{' !== \mb_substr($this->message, $index, 1)) {
                throw new \RuntimeException('No message fragment after plural/select selector.');
            }

            $index = $this->parse($index, 1, $nestingLevel + 1, $type);
            $isEmpty = false;
        }
    }

    private function parseArgument(int $index, int $startLength, int $nestingLevel): int
    {
        $argumentStart = \count($this->tokens);
        $argumentType = Token::ARG_TYPE_NONE;
        $this->pushToken(Token::TYPE_ARG_START, $index, $startLength, $argumentType);

        if ($this->messageLength === ($nameIndex = $index = $this->skipWhitespace($index + $startLength))) {
            throw new \RuntimeException('Unmatched braces.');
        }

        $index = $this->skipIdentifier($index);
        $number = $this->parseArgNumber(\mb_substr($this->message, $nameIndex, $index - $nameIndex));
        if (\is_int($number)) {
            $this->pushToken(Token::TYPE_ARG_NUMBER, $nameIndex, $index - $nameIndex, $number);
        } elseif (\is_string($number)) {
            $this->pushToken(Token::TYPE_ARG_NAME, $nameIndex, $index - $nameIndex, $number);
        } else {
            throw new \RuntimeException('Pattern syntax error.');
        }

        if ($this->messageLength === $index = $this->skipWhitespace($index)) {
            throw new \RuntimeException('Unmatched braces.');
        }

        if ('}' === $c = \mb_substr($this->message, $index, 1)) {
        } elseif (',' !== $c) {
            throw new \RuntimeException('Pattern syntax error.');
        } else {
            $typeIndex = $index = $this->skipWhitespace($index + 1);

            while($index < $this->messageLength && \ctype_alpha(\mb_substr($this->message, $index, 1))) {
                ++$index;
            }

            $typeLength = $index - $typeIndex;
            $index = $this->skipWhitespace($index);

            if($index === $this->messageLength) {
                throw new \RuntimeException('Unmatched braces.');
            }

            if (0 === $typeLength || ((',' !== $c = \mb_substr($this->message, $index, 1)) && '}' !== $c)) {
                throw new \RuntimeException('Pattern syntax error.');
            }

            if (\array_key_exists($type = \mb_strtolower(\mb_substr($this->message, $typeIndex, $index - $typeIndex)), $types = [
                'choice' => Token::ARG_TYPE_CHOICE,
                'plural' => Token::ARG_TYPE_PLURAL,
                'select' => Token::ARG_TYPE_SELECT,
                'selectordinal' => Token::ARG_TYPE_SELECT_ORDINAL,
            ])) {
                $argType = $types[$type];
            } else {
                $argType = Token::ARG_TYPE_SIMPLE;
            }

            $this->tokens[$argumentStart]->setValue($argType);

            if(Token::ARG_TYPE_SIMPLE === $argType) {
                $this->pushToken(Token::ARG_TYPE_SIMPLE, $typeIndex, $typeLength, null);
            }

            // look for an argument style (pattern)
            if('}' === $c && Token::ARG_TYPE_SIMPLE !== $argType) {
                throw new \RuntimeException('Pattern syntax error.');
            } else /* ',' */ {
                ++$index;
                if (Token::ARG_TYPE_SIMPLE === $argType) {
                    $index = $this->parseSimpleStyle($index);
                } elseif(Token::ARG_TYPE_CHOICE === $argType) {
                    $index = $this->parseChoiceStyle($index, $nestingLevel);
                } else {
                    $index = $this->parsePluralOrSelectStyle($argType, $index, $nestingLevel);
                }
            }
        }

        $this->pushLimitToken($argumentStart, Token::TYPE_ARG_LIMIT, $index, 1, $argumentType);

        return $index + 1;
    }

    private function parseArgNumber(string $value)
    {
        if (\ctype_digit($value)) {
            return (int) $value;
        } else {
            return $value;
        }
    }

    private function parseDouble(string $value, bool $allowInfinity)
    {
        if (false !== \mb_strpos($value, '∞')) {
            if (!$allowInfinity) {
                throw new \RuntimeException('Bad syntax for numeric value (infinity is not allowed).');
            }

            if ('∞' === $value || '+∞' === $value) {
                return \INF;
            }

            if ('-∞' === $value) {
                return -\INF;
            }

            throw new \RuntimeException('Bad syntax for numeric value.');
        }

        if (false !== \mb_strpos($value, '.')) {
            return (float) $value;
        }

        return (int) $value;
    }

    private function pushToken(string $type, int $index, int $length, $value): void
    {
        $this->tokens[] = new Token($type, $index,  $length, $value);
    }

    private function pushLimitToken(int $argStart, string $type, int $index, int $length, $value): void
    {
        $this->tokens[$argStart]->setLimit(\count($this->tokens));
        $this->pushToken($type, $index, $length, $value);
    }

    private function skipIdentifier(int $index): int
    {
        $syntaxPattern = '/[!-\/\:-@\[-\^`\{-~\x{00A1}-\x{00A7}\x{00A9}\x{00AB}\x{00AC}\x{00AE}\x{00B0}\x{00B1}\x{00B6}\x{00BB}\x{00BF}\x{00D7}\x{00F7}\x{2010}-\x{2027}\x{2030}-\x{203E}\x{2041}-\x{2053}\x{2055}-\x{205E}\x{2190}-\x{245F}\x{2500}-\x{2775}\x{2794}-\x{2BFF}\x{2E00}-\x{2E7F}\x{3001}-\x{3003}\x{3008}-\x{3020}\x{3030}\x{FD3E}\x{FD3F}\x{FE45}\x{FE46}]/u';

        while ('' !== ($c = \mb_substr($this->message, $index, 1)) && !\IntlChar::isWhitespace($c) && !\preg_match($syntaxPattern, $c)) {
            ++$index;
        }

        return $index;
    }

    private function skipDouble(int $index): int
    {
        while($index < $this->messageLength) {
            $c = \mb_substr($this->message, $index, 1);

            // U+221E: Allow the infinity symbol, for ChoiceFormat patterns.
            if (($c < '0' && '+' !== $c && '-' !== $c && '.' !== $c) || ($c > '9' && 'e' !== $c && 'E' !== $c && $c !== '∞')) {
                break;
            }

            ++$index;
        }

        return $index;
    }

    private function skipWhitespace(int $index): int
    {
        while ('' !== ($c = \mb_substr($this->message, $index, 1)) && \IntlChar::isWhitespace($c)) {
            ++$index;
        }

        return $index;
    }

    private static function hasPluralStyle(?string $type): bool
    {
        return Token::ARG_TYPE_PLURAL === $type || Token::ARG_TYPE_SELECT_ORDINAL === $type;
    }

    private function inMessageFormatPattern(int $nestingLevel): bool
    {
        return 0 < $nestingLevel || Token::TYPE_MSG_START === $this->tokens[0]->getType();
    }

    private function inTopLevelChoiceMessage(int $nestingLevel, ?string $parentType): bool
    {
        return
            1 === $nestingLevel
            && Token::ARG_TYPE_CHOICE === $parentType
            && Token::TYPE_MSG_START !== $this->tokens[0]->getType()
        ;
    }
}
