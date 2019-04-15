<?php

namespace Deguif\Icu;

final class Token
{
    /**
     * Start of a message pattern (main or nested).
     * The length is 0 for the top-level message
     * and for a choice argument sub-message, otherwise 1 for the '{'.
     * The value indicates the nesting level, starting with 0 for the main message.
     *
     * There is always a later TYPE_MSG_LIMIT part.
     */
    public const TYPE_MSG_START = 'msg_start';

    /**
     * End of a message pattern (main or nested).
     * The length is 0 for the top-level message and
     * the last sub-message of a choice argument,
     * otherwise 1 for the '}' or (in a choice argument style) the '|'.
     * The value indicates the nesting level, starting with 0 for the main message.
     */
    public const TYPE_MSG_LIMIT = 'msg_limit';

    /**
     * Indicates a substring of the pattern string which is to be skipped when formatting.
     * For example, an apostrophe that begins or ends quoted text
     * would be indicated with such a part.
     * The value is undefined and currently always 0.
     */
    public const TYPE_SKIP_SYNTAX = 'skip_syntax';

    /**
     * Indicates that a syntax character needs to be inserted for auto-quoting.
     * The length is 0.
     * The value is the character code of the insertion character. (U+0027=APOSTROPHE)
     */
    public const TYPE_INSERT_CHAR = 'insert_char';

    /**
     * Indicates a syntactic (non-escaped) # symbol in a plural variant.
     * When formatting, replace this part's substring with the
     * (value-offset) for the plural argument value.
     * The value is undefined and currently always 0.
     */
    public const TYPE_REPLACE_NUMBER = 'replace_number';

    /**
     * Start of an argument.
     * The length is 1 for the '{'.
     * The value is the ordinal value of the ArgType. Use getArgType().
     *
     * This part is followed by either an TYPE_ARG_NUMBER or TYPE_ARG_NAME,
     * followed by optional argument sub-parts (see MessagePatternPartType constants)
     * and finally an TYPE_ARG_LIMIT part.
     */
    public const TYPE_ARG_START = 'arg_start';

    /**
     * End of an argument.
     * The length is 1 for the '}'.
     * The value is the ordinal value of the ArgType. Use getArgType().
     */
    public const TYPE_ARG_LIMIT = 'arg_limit';

    /**
     * The argument number, provided by the value.
     */
    public const TYPE_ARG_NUMBER = 'arg_number';

    /**
     * The argument name.
     * The value is undefined and currently always 0.
     */
    public const TYPE_ARG_NAME = 'arg_name';

    /**
     * The argument type.
     * The value is undefined and currently always 0.
     */
    public const TYPE_ARG_TYPE = 'arg_type';

    /**
     * The argument style text.
     * The value is undefined and currently always 0.
     */
    public const TYPE_ARG_STYLE = 'arg_style';

    /**
     * A selector substring in a "complex" argument style.
     * The value is undefined and currently always 0.
     */
    public const TYPE_ARG_SELECTOR = 'arg_selector';

    /**
     * An integer value, for example the offset or an explicit selector value
     * in a PluralFormat style.
     * The part value is the integer value.
     */
    public const TYPE_ARG_INT = 'arg_int';

    /**
     * A numeric value, for example the offset or an explicit selector value
     * in a PluralFormat style.
     * The part value is an index into an internal array of numeric values;
     * use getNumericValue().
     */
    public const TYPE_ARG_DOUBLE = 'arg_double';

    /**
     * The argument has no specified type.
     */
    public const ARG_TYPE_NONE = null;

    /**
     * The argument has a "simple" type which is provided by the TYPE_ARG_TYPE part.
     * An TYPE_ARG_STYLE part might follow that.
     */
    public const ARG_TYPE_SIMPLE = 'simple';

    /**
     * The argument is a ChoiceFormat with one or more
     * ((TYPE_ARG_INT | TYPE_ARG_DOUBLE), TYPE_ARG_SELECTOR, message) tuples.
     */
    public const ARG_TYPE_CHOICE = 'choice';

    /**
     * The argument is a cardinal-number PluralFormat with an optional TYPE_ARG_INT or TYPE_ARG_DOUBLE offset
     * (e.g., offset:1)
     * and one or more (TYPE_ARG_SELECTOR [explicit-value] message) tuples.
     * If the selector has an explicit value (e.g., =2), then
     * that value is provided by the TYPE_ARG_INT or TYPE_ARG_DOUBLE part preceding the message.
     * Otherwise the message immediately follows the TYPE_ARG_SELECTOR.
     */
    public const ARG_TYPE_PLURAL = 'plural';

    /**
     * The argument is a SelectFormat with one or more (TYPE_ARG_SELECTOR, message) pairs.
     */
    public const ARG_TYPE_SELECT = 'select';

    /**
     * The argument is an ordinal-number PluralFormat
     * with the same style parts sequence and semantics as ARG_TYPE_PLURAL.
     */
    public const ARG_TYPE_SELECT_ORDINAL = 'select_ordinal';

    private $type;
    private $index;
    private $length;
    private $value;
    private $limit;

    public function __construct(string $type, int $index, int $length, $value)
    {
        $this->type = $type;
        $this->index = $index;
        $this->length = $length;
        $this->value = $value;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }
}
