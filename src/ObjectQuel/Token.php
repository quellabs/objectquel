<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\ReflectionManagement\BasicEnum;
	
	/**
	 * Class Token
	 * @package Quellabs\ObjectQuel\AnnotationsReader
	 */
	class Token extends BasicEnum {
		const None = 0;
		const Eof = 1;
		const Annotation = 2;
		const Comma = 3;
		const Dot = 4;
		const ParenthesesOpen = 5;
		const ParenthesesClose = 6;
		const CurlyBraceOpen = 7;
		const CurlyBraceClose = 8;
		const Equals = 9;
		const LargerThan = 10;
		const SmallerThan = 11;
		const String = 12;
		const Number = 13;
		const Identifier = 14;
		const True = 15;
		const False = 16;
		const BracketOpen = 17;
		const BracketClose = 18;
		const Plus = 19;
		const Minus = 20;
		const Underscore = 21;
		const Star = 22;
		const Variable = 23;
		const Colon = 24;
		const Semicolon = 25;
		const Slash = 26;
		const Backslash = 27;
		const Pipe = 28;
		const Percentage = 29;
		const Hash = 30;
		const Ampersand = 31;
		const Hat = 32;
		const Copyright = 33;
		const Pound = 34;
		const Euro = 35;
		const Exclamation = 36;
		const Question = 37;
		const Equal = 38;
		const Unequal = 39;
		const LargerThanOrEqualTo = 40;
		const SmallerThanOrEqualTo = 41;
		const BinaryShiftLeft = 42;
		const BinaryShiftRight = 43;
		const Parameter = 44;
		const Null = 45;
		const Arrow = 46;
		const CompilerDirective = 47;
		const Retrieve = 100;
		const Where = 101;
		const And = 102;
		const Or = 103;
		const Range = 104;
		const Of = 105;
		const Is = 106;
		const In = 107;
		const Via = 108;
		const Unique = 110;
		const Sort = 111;
		const By = 112;
		const RegExp = 113;
		const Not = 114;
		const Asc = 115;
		const Desc = 116;
		const Window = 117;
		const Using = 118;
		const WindowSize = 119;
		const JsonSource = 120;
		
		protected int $type;
		protected mixed $value;
		protected int $lineNumber;
		protected array $extraData;
		
		/**
		 * Token constructor.
		 * @param int $type
		 * @param null $value
		 * @param int $lineNumber
		 * @param array $extraData
		 */
		public function __construct(int $type, $value = null, int $lineNumber = 0, array $extraData = []) {
			$this->type = $type;
			$this->value = $value;
			$this->lineNumber = $lineNumber;
			$this->extraData = $extraData;
		}
		
		/**
		 * Returns the Token type
		 * @return int
		 */
		public function getType(): int {
			return $this->type;
		}
		
		/**
		 * Returns the (optional) value or null if there is none
		 * @return mixed
		 */
		public function getValue(): mixed {
			return $this->value;
		}
		
		/**
		 * Returns the line number the token was found on
		 * @return int
		 */
		public function getLineNumber(): int {
			return $this->lineNumber;
		}
		
		/**
		 * Returns the (optional) extra data for this token
		 * @return array
		 */
		public function getExtraData(): array {
			return $this->extraData;
		}
	}