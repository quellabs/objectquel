<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\ReflectionManagement\BasicEnum;
	
	/**
	 * Class Token
	 * @package Quellabs\ObjectQuel\AnnotationsReader
	 */
	class Token extends BasicEnum {
		const int None = 0;
		const int Eof = 1;
		const int Annotation = 2;
		const int Comma = 3;
		const int Dot = 4;
		const int ParenthesesOpen = 5;
		const int ParenthesesClose = 6;
		const int CurlyBraceOpen = 7;
		const int CurlyBraceClose = 8;
		const int Equals = 9;
		const int LargerThan = 10;
		const int SmallerThan = 11;
		const int String = 12;
		const int Number = 13;
		const int Identifier = 14;
		const int True = 15;
		const int False = 16;
		const int BracketOpen = 17;
		const int BracketClose = 18;
		const int Plus = 19;
		const int Minus = 20;
		const int Underscore = 21;
		const int Star = 22;
		const int Variable = 23;
		const int Colon = 24;
		const int Semicolon = 25;
		const int Slash = 26;
		const int Backslash = 27;
		const int Pipe = 28;
		const int Percentage = 29;
		const int Hash = 30;
		const int Ampersand = 31;
		const int Hat = 32;
		const int Copyright = 33;
		const int Pound = 34;
		const int Euro = 35;
		const int Exclamation = 36;
		const int Question = 37;
		const int Equal = 38;
		const int Unequal = 39;
		const int LargerThanOrEqualTo = 40;
		const int SmallerThanOrEqualTo = 41;
		const int BinaryShiftLeft = 42;
		const int BinaryShiftRight = 43;
		const int Parameter = 44;
		const int Null = 45;
		const int Arrow = 46;
		const int CompilerDirective = 47;
		const int Retrieve = 100;
		const int Where = 101;
		const int And = 102;
		const int Or = 103;
		const int Range = 104;
		const int Of = 105;
		const int Is = 106;
		const int In = 107;
		const int Via = 108;
		const int Unique = 110;
		const int Sort = 111;
		const int By = 112;
		const int RegExp = 113;
		const int Not = 114;
		const int Asc = 115;
		const int Desc = 116;
		const int Window = 117;
		const int Using = 118;
		const int WindowSize = 119;
		const int JsonSource = 120;
		const int Filter = 121;
		
		protected int $type;
		protected mixed $value;
		protected int $lineNumber;
		
		/** @var array<string, mixed> */
		protected array $extraData;
		
		/**
		 * Token constructor.
		 * @param int $type
		 * @param mixed $value
		 * @param int $lineNumber
		 * @param array<string, mixed> $extraData
		 */
		public function __construct(int $type, mixed $value = null, int $lineNumber = 0, array $extraData = []) {
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
		 * Returns the token value as a string.
		 * Only valid for tokens that carry a string value (Annotation, Parameter, String).
		 * @return string
		 */
		public function getStringValue(): string {
			return is_string($this->value) ? $this->value : '';
		}
		
		/**
		 * Returns the token value as a number.
		 * Only valid for tokens that carry a numeric value (Number).
		 * @return float|int
		 */
		public function getNumericValue(): float|int {
			if (!is_int($this->value) && !is_float($this->value)) {
				throw new \LogicException('getNumericValue() called on a non-numeric token');
			}
			
			return $this->value;
		}
		
		/**
		 * Returns the (optional) extra data for this token
		 * @return array<string, mixed>
		 */
		public function getExtraData(): array {
			return $this->extraData;
		}
	}