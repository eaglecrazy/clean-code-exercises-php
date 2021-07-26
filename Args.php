<?php

namespace CleanCode;

use CleanCode\ArgumentMarshaler\BooleanArgumentMarshaler;
use CleanCode\ArgumentMarshaler\IntegerArgumentMarshaler;
use CleanCode\ArgumentMarshaler\StringArgumentMarshaler;
use CleanCode\Exceptions\ArgsException;
use CleanCode\Exceptions\NumberFormatException;
use CleanCode\Exceptions\ParseException;
use Exception;

/**
 * Class Args
 *
 * @package CleanCode
 */
class Args
{
    private const STRING_KEY_END = '*';
    private const INTEGER_KEY_END = '#';

    private string $schema;
    private array $args;
    private bool $valid = true;
    private array $unexpectedArguments = [];
    private array $marshalers = [];
    private array $argsFound = [];
    private string $errorArgument = '\0';
    private array $argumentsParseErrors = [];

    /**
     * @param string $schema
     * @param array $args
     * @throws ParseException
     */
    public function __construct(string $schema, array $args)
    {
        $this->schema = $schema;
        $this->args = $args;
        $this->valid = $this->parse();
    }

    /**
     * Analyze the schema and arguments.
     *
     * @return bool
     * @throws ParseException
     */
    private function parse(): bool
    {
        if (strlen($this->schema) == 0 && count($this->args) == 0) {
            return true;
        }

        $this->parseSchema();

        try {
            $this->parseArguments();
        } catch (ArgsException $e) {
            $this->valid = false;
        }

        return $this->valid;
    }

    /**
     * Analyze the schema.
     *
     * @throws ParseException
     */
    private function parseSchema(): void
    {
        $schema = explode(',', $this->schema);

        foreach ($schema as $element) {
            if (strlen($element)) {
                $trimmedElement = trim($element);
                $this->parseSchemaElement($trimmedElement);
            }
        }
    }

    /**
     *  Analyze the element of schema.
     *
     * @param string $element
     * @throws ParseException
     */
    private function parseSchemaElement(string $element): void
    {
        $elementId = $element[0];

        $elementTail = substr($element, 1);

        $this->validateSchemaElementId($elementId);

        if ($this->isBooleanSchemaElement($elementTail)) {
            $this->parseBooleanSchemaElement($elementId);
        } else if ($this->isStringSchemaElement($elementTail)) {
            $this->parseStringSchemaElement($elementId);
        } else if ($this->isIntegerSchemaElement($elementTail)) {
            $this->parseIntegerSchemaElement($elementId);
        } else {
            throw new ParseException("Unknown element:" . $elementId . " in schema: " . $this->schema, 0);
        }
    }

    /**
     * Validate schema element.
     *
     * @param string $elementId
     * @throws ParseException
     */
    private function validateSchemaElementId(string $elementId): void
    {
        if (!ctype_alpha($elementId)) {
            throw new ParseException("Bad character:" . $elementId . " in schema: " . $this->schema, 0);
        }
    }

    /**
     * Create storage for boolean element.
     *
     * @param string $elementId
     */
    private function parseBooleanSchemaElement(string $elementId): void
    {
        $this->marshalers[$elementId] = new BooleanArgumentMarshaler();
    }

    /**
     * Create storage for integer element.
     *
     * @param string $elementId
     */
    private function parseIntegerSchemaElement(string $elementId): void
    {
        $this->marshalers[$elementId] = new IntegerArgumentMarshaler();
    }

    /**
     * Create storage for string element.
     *
     * @param string $elementId
     */
    private function parseStringSchemaElement(string $elementId): void
    {
        $this->marshalers[$elementId] = new StringArgumentMarshaler();
    }

    /**
     * Determine if it is a string element.
     *
     * @param string $elementTail
     * @return bool
     */
    private function isStringSchemaElement(string $elementTail): bool
    {
        return $elementTail === self::STRING_KEY_END;
    }

    /**
     * Determine if it is a boolean element.
     *
     * @param string $elementTail
     * @return bool
     */
    private function isBooleanSchemaElement(string $elementTail): bool
    {
        return $elementTail === '';
    }

    /**
     * Determine if it is a integer element.
     *
     * @param string $elementTail
     * @return bool
     */
    private function isIntegerSchemaElement(string $elementTail): bool
    {
        return $elementTail === self::INTEGER_KEY_END;
    }

    /**
     * Analyze the arguments.
     *
     * @throws ArgsException
     */
    private function parseArguments(): void
    {
        foreach ($this->args as $arg) {
            $this->parseArgument($arg);
        }
    }

    /**
     * Analyze the argument.
     *
     * @param string $arg
     * @throws ArgsException
     */
    private function parseArgument(string $arg): void
    {
        if ($arg[0] == '-' && strlen($arg) >= 2) {
            $this->parseElement($arg);
        } else {
            $this->unexpectedArguments[] = $arg;
            $this->argumentsParseErrors[] = new ParseError(ErrorCodeEnum::UNEXPECTED_ARGUMENT(), $arg);
            throw new ArgsException();
        }
    }

    /**
     * Analyze element.
     *
     * @param string $arg
     * @throws ArgsException
     */
    private function parseElement(string $arg): void
    {
        if ($this->setArgument($arg)) {
            $this->argsFound[] = $arg;
        } else {
            $this->unexpectedArguments[] = $arg;
            $this->argumentsParseErrors[] = new ParseError(ErrorCodeEnum::UNEXPECTED_ARGUMENT(), $arg);
            throw new ArgsException();
        }
    }

    /**
     * Set the argument.
     *
     * @param string $arg
     * @return bool
     * @throws ArgsException
     */
    private function setArgument(string $arg): bool
    {
        $argType = substr($arg, 1, 1);

        if ($this->isBooleanArg($argType)) {
            //If a boolean argument was specified, then it is true.
            $this->setBooleanArg($argType);
        } else if ($this->isStringArg($argType)) {
            $this->setStringArg($argType, $arg);
        } else if ($this->isIntArg($argType)) {
            $this->setIntArg($argType, $arg);
        } else {
            return false;
        }

        return true;
    }

    /**
     * Determine if there is such a integer element in the schema.
     *
     * @param string $key
     * @return bool
     */
    private function isIntArg(string $key): bool
    {
        $marshaler = $this->marshalers[$key] ?? null;

        return ($marshaler instanceof IntegerArgumentMarshaler);
    }

    /**
     * Set integer argument.
     *
     * @param string $key
     * @param string $arg
     * @throws ArgsException
     */
    private function setIntArg(string $key, string $arg): void
    {
        if ('-' . $key === $arg) {
            $this->errorArgument = $arg;
            $this->argumentsParseErrors[] = new ParseError(ErrorCodeEnum::MISSING_INTEGER(), $arg);
            throw new ArgsException();
        };

        $int = substr($arg, 2);

        /** @var IntegerArgumentMarshaler $integerMarshaler */
        $integerMarshaler = $this->marshalers[$key];

        try{
            $integerMarshaler->set($int);
        } catch (NumberFormatException $e){
            $this->errorArgument = $arg;
            $this->argumentsParseErrors[] = new ParseError(ErrorCodeEnum::INVALID_INTEGER(), $arg);
            throw new ArgsException();
        }
    }

    /**
     * Set string argument.
     *
     * @param string $key
     * @param string $arg
     * @throws ArgsException
     */
    private function setStringArg(string $key, string $arg): void
    {
        $str = substr($arg, 2);

        if ($arg === '-' . $key) {
            $this->errorArgument = $str;
            $this->argumentsParseErrors[] = new ParseError(ErrorCodeEnum::MISSING_STRING(), $arg);
            throw new ArgsException();
        }

        /** @var StringArgumentMarshaler $stringMarshaler */
        $stringMarshaler = $this->marshalers[$key];

        $stringMarshaler->set($str);
    }

    /**
     * Determine if there is such a string element in the schema.
     *
     * @param string $key
     * @return bool
     */
    private function isStringArg(string $key): bool
    {
        $marshaler = $this->marshalers[$key] ?? null;

        return ($marshaler instanceof StringArgumentMarshaler);
    }

    /**
     * Set boolean argument.
     *
     * @param string $key
     */
    private function setBooleanArg(string $key): void
    {
        /** @var BooleanArgumentMarshaler $booleanMarshaler */
        $booleanMarshaler = $this->marshalers[$key];

        $booleanMarshaler->set('true');
    }

    /**
     * Determine if there is such a boolean element in the schema.
     *
     * @param string $key
     * @return bool
     */
    private function isBooleanArg(string $key): bool
    {
        $marshaler = $this->marshalers[$key] ?? null;

        return ($marshaler instanceof BooleanArgumentMarshaler);
    }

    /**
     * Get the number of arguments.
     *
     * @return int
     */
    public function cardinality(): int
    {
        return count($this->argsFound);
    }

    /**
     * Get the scheme.
     *
     * @return string
     */
    public function usage(): string
    {
        if (strlen($this->schema) > 0) {
            return '-[' . $this->schema . ']';
        } else {
            return '';
        }
    }

    /**
     * Get error message.
     *
     * @return string
     * @throws Exception
     */
    public function errorMessage(): string
    {
        $message = '';
        $newer = 'This cannot be, because this can never be!!!';

        /** @var ParseError $error */
        foreach ($this->argumentsParseErrors as $error) {
            switch ($error->getErrorCode()) {
                case ErrorCodeEnum::MISSING_STRING() :
                    $message .= 'Could not find string parameter for ' . $error->getArgument() . '.' . PHP_EOL;
                    break;
                case ErrorCodeEnum::MISSING_INTEGER() :
                    $message .= 'Could not find integer parameter for ' . $error->getArgument() . '.' . PHP_EOL;
                    break;
                case ErrorCodeEnum::INVALID_INTEGER() :
                    $message .= 'Invalid integer parameter: ' . $error->getArgument() . '.' . PHP_EOL;
                    break;
                case ErrorCodeEnum::UNEXPECTED_ARGUMENT() :
                    $message .= 'Argument "' . $error->getArgument() . '" unexpected.' . PHP_EOL;
                    break;
                case ErrorCodeEnum::OK() :
                    throw new Exception($newer);
                default :
                    throw new Exception($newer);
            }
        }

        return $message;
    }

    /**
     * Get a boolean argument.
     *
     * @param string $key
     * @return bool
     */
    public function getBoolean(string $key): bool
    {
        /** @var BooleanArgumentMarshaler $am */
        $am = $this->marshalers[$key] ?? null;

        return $am ? $am->get() : false;
    }

    /**
     * Get a string argument.
     *
     * @param string $key
     * @return string
     */
    public function getString(string $key): string
    {
        /** @var StringArgumentMarshaler $am */
        $am = $this->marshalers[$key] ?? null;

        return $am ? $am->get() : '';
    }

    /**
     * Get a integer argument.
     *
     * @param string $key
     * @return integer|null
     */
    public function getInt(string $key): int
    {
        /** @var IntegerArgumentMarshaler $am */
        $am = $this->marshalers[$key] ?? null;

        return $am ? $am->get() : 0;
    }

    /**
     * Determine if an argument exists.
     *
     * @param string $arg
     * @return bool
     */
    public function has(string $arg): bool
    {
        return in_array($arg, $this->argsFound);
    }

    /**
     * Get the state of validation.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->valid;
    }
}
