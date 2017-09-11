<?php
  /**
   * RTF parser/formatter
   *
   * This code reads RTF files and formats the RTF data to HTML.
   *
   * PHP version 5
   *
   * @author	 Alexander van Oostenrijk
   * @copyright  2014 Alexander van Oostenrijk
   * @license	GNU
   * @version	1
   * @link		 http://www.independent-software.com
   * 
   * Sample of use:
   * 
   * $reader = new RtfReader();
   * $rtf = file_get_contents("test.rtf"); // or use a string
   * $reader->Parse($rtf);
   * //$reader->root->dump(); // to see what the reader read
   * $formatter = new RtfHtml();
   * echo $formatter->Format($reader->root);   
   */
 
class RtfElement
{
	protected function Indent($level)
	{
		for($i = 0; $i < $level * 2; $i++) echo "&nbsp;";
	}
}
 
class RtfGroup extends RtfElement
{
	public $parent;
	public $children;
 
	public function __construct()
	{
		$this->parent = null;
		$this->children = array();
	}
 
	public function GetType()
	{
		// No children?
		if(sizeof($this->children) == 0) return null;
		// First child not a control word?
		$child = $this->children[0];
		if(get_class($child) != "RtfControlWord") return null;
		return $child->word;
	}	
 
	public function IsDestination()
	{
		// No children?
		if(sizeof($this->children) == 0) return null;
		// First child not a control symbol?
		$child = $this->children[0];
		if(get_class($child) != "RtfControlSymbol") return null;
		return $child->symbol == '*';
	}
 
	public function dump($level = 0)
	{
		echo "<div>";
		$this->Indent($level);
		echo "{";
		echo "</div>";
 
		foreach($this->children as $child)
		{
		if(get_class($child) == "RtfGroup")
		{
			if ($child->GetType() == "fonttbl") continue;
			if ($child->GetType() == "colortbl") continue;
			if ($child->GetType() == "stylesheet") continue;
			if ($child->GetType() == "info") continue;
			// Skip any pictures:
			if (substr($child->GetType(), 0, 4) == "pict") continue;
			if ($child->IsDestination()) continue;
		}
		$child->dump($level + 2);
		}
 
		echo "<div>";
		$this->Indent($level);
		echo "}";
		echo "</div>";
	}
}
 
class RtfControlWord extends RtfElement
{
	public $word;
	public $parameter;
 
	public function dump($level)
	{
		echo "<div style='color:green'>";
		$this->Indent($level);
		echo "WORD {$this->word} ({$this->parameter})";
		echo "</div>";
	}
}
 
class RtfControlSymbol extends RtfElement
{
	public $symbol;
	public $parameter = 0;
 
	public function dump($level)
	{
		echo "<div style='color:blue'>";
		$this->Indent($level);
		echo "SYMBOL {$this->symbol} ({$this->parameter})";
		echo "</div>";
	}	
}
 
class RtfText extends RtfElement
{
	public $text;
 
	public function dump($level)
	{
		echo "<div style='color:red'>";
		$this->Indent($level);
		echo "TEXT {$this->text}";
		echo "</div>";
	}	
}
 
class RtfReader
{
	public $root = null;
 
	protected function GetChar()
	{
		$this->char = $this->rtf[$this->pos++];
	}
 
	protected function ParseStartGroup()
	{
		// Store state of document on stack.
		$group = new RtfGroup();
		if($this->group != null) $group->parent = $this->group;
		if($this->root == null)
		{
		$this->group = $group;
		$this->root = $group;
		}
		else
		{
		array_push($this->group->children, $group);
		$this->group = $group;
		}
	}
 
	protected function is_letter()
	{
		if(ord($this->char) >= 65 && ord($this->char) <= 90) return TRUE;
		if(ord($this->char) >= 97 && ord($this->char) <= 122) return TRUE;
		return FALSE;
	}
 
	protected function is_digit()
	{
		if(ord($this->char) >= 48 && ord($this->char) <= 57) return TRUE;
		return FALSE;
	}
 
	protected function ParseEndGroup()
	{
		// Retrieve state of document from stack.
		$this->group = $this->group->parent;
	}
 
	protected function ParseControlWord()
	{
		$this->GetChar();
		$word = "";

		while($this->is_letter())
		{
		$word .= $this->char;
		$this->GetChar();
		}
 
		// Read parameter (if any) consisting of digits.
		// Paramater may be negative.
		$parameter = null;
		$negative = false;
		if($this->char == '-') 
		{
		$this->GetChar();
		$negative = true;
		}
		while($this->is_digit())
		{
		if($parameter == null) $parameter = 0;
		$parameter = $parameter * 10 + $this->char;
		$this->GetChar();
		}
		if($parameter === null) $parameter = 1;
		if($negative) $parameter = -$parameter;
 
		// If this is \u, then the parameter will be followed by 
		// a character.
		if($word == "u") 
		{
		}
		// If the current character is a space, then
		// it is a delimiter. It is consumed.
		// If it's not a space, then it's part of the next
		// item in the text, so put the character back.
		else
		{
		if($this->char != ' ') $this->pos--; 
		}
 
		$rtfword = new RtfControlWord();
		$rtfword->word = $word;
		$rtfword->parameter = $parameter;
		array_push($this->group->children, $rtfword);
	}
 
	protected function ParseControlSymbol()
	{
		// Read symbol (one character only).
		$this->GetChar();
		$symbol = $this->char;
 
		// Symbols ordinarily have no parameter. However, 
		// if this is \', then it is followed by a 2-digit hex-code:
		$parameter = 0;
		if($symbol == '\'')
		{
		$this->GetChar(); 
		$parameter = $this->char;
		$this->GetChar(); 
		$parameter = hexdec($parameter . $this->char);
		}
 
		$rtfsymbol = new RtfControlSymbol();
		$rtfsymbol->symbol = $symbol;
		$rtfsymbol->parameter = $parameter;
		array_push($this->group->children, $rtfsymbol);
	}
 
	protected function ParseControl()
	{
		// Beginning of an RTF control word or control symbol.
		// Look ahead by one character to see if it starts with
		// a letter (control world) or another symbol (control symbol):
		$this->GetChar();
		$this->pos--;
		if($this->is_letter()) 
		$this->ParseControlWord();
		else
		$this->ParseControlSymbol();
	}
 
	protected function ParseText()
	{
		// Parse plain text up to backslash or brace,
		// unless escaped.
		$text = "";

		do
		{
		$terminate = false;
		$escape = false;
 
		// Is this an escape?
		if($this->char == '\\')
		{
			// Perform lookahead to see if this
			// is really an escape sequence.
			$this->GetChar();
			switch($this->char)
			{
			case '\\': $text .= '\\'; break;
			case '{':  $text .= '{';  break;
			case '}':  $text .= '}';  break;
			default:
				// Not an escape. Roll back.
				$this->pos = $this->pos - 2;
				$terminate = true;
				break;
			}
		}
		else if($this->char == '{' || $this->char == '}')
		{
			$this->pos--;
			$terminate = true;
		}
 
		if(!$terminate && !$escape)
		{
			$text .= $this->char;
			$this->GetChar();
		}
		}
		while(!$terminate && $this->pos < $this->len);
 
		$rtftext = new RtfText();
		$rtftext->text = $text;

		// If group does not exist, then this is not a valid RTF file. Throw an exception.
		if($this->group == NULL) {
		throw new Exception();
		}

		array_push($this->group->children, $rtftext);
	}
 
	/*
	 * Attempt to parse an RTF string. Parsing returns TRUE on success or FALSE on failure
	 */
	public function Parse($rtf)
	{
		try {
		$this->rtf = $rtf;
		$this->pos = 0;
		$this->len = strlen($this->rtf);
		$this->group = null;
		$this->root = null;

		while($this->pos < $this->len)
		{
			// Read next character:
			$this->GetChar();

			// Ignore \r and \n
			if($this->char == "\n" || $this->char == "\r") continue;

			// What type of character is this?
			switch($this->char)
			{
			case '{':
				$this->ParseStartGroup();
				break;
			case '}':
				$this->ParseEndGroup();
				break;
			case '\\':
				$this->ParseControl();
				break;
			default:
				$this->ParseText();
				break;
			}
		}

		return TRUE;
		}
		catch(Exception $ex) {
		return FALSE;
		}
	}
}
 
class RtfState
{
	public function __construct()
	{
		$this->Reset();
	}
 
	public function Reset()
	{
		$this->bold = false;
		$this->italic = false;
		$this->underline = false;
		$this->end_underline = false;
		$this->strike = false;
		$this->hidden = false;
		$this->fontsize = 0;
		$this->par = false;
		
		$this->class = array();
	}
}
 
class RtfHtml
{
	public function Format($root)
	{
		$this->output = "";
		// Create a stack of states:
		$this->states = array();
		// Put an initial standard state onto the stack:
		$this->state = new RtfState();
		array_push($this->states, $this->state);
		$this->FormatGroup($root);
		return $this->output;
	}
 
	protected function FormatGroup($group)
	{
		// Can we ignore this group?
		if ($group->GetType() == "fonttbl") return;
		if ($group->GetType() == "colortbl") return;
		if ($group->GetType() == "stylesheet") return;
		if ($group->GetType() == "info") return;
		// Skip any pictures:
		if (substr($group->GetType(), 0, 4) == "pict") return;
		if ($group->IsDestination()) return;
 
		// Push a new state onto the stack:
		$this->state = clone $this->state;
		array_push($this->states, $this->state);
 
		foreach($group->children as $child)
		{
			if(get_class($child) == "RtfGroup") $this->FormatGroup($child);
			if(get_class($child) == "RtfControlWord") $this->FormatControlWord($child);
			if(get_class($child) == "RtfControlSymbol") $this->FormatControlSymbol($child);
			if(get_class($child) == "RtfText") $this->FormatText($child);
		}
 
		// Pop state from stack.
		array_pop($this->states);
		$this->state = $this->states[sizeof($this->states)-1];
	}
 
	protected function FormatControlWord($word)
	{
		if($word->word == "plain") $this->state->Reset();
		if($word->word == "b") $this->state->bold = $word->parameter;
		if($word->word == "i") $this->state->italic = $word->parameter;
		if($word->word == "ul") $this->state->underline = $word->parameter;
		if($word->word == "ulnone") $this->state->end_underline = $word->parameter;
		if($word->word == "strike") $this->state->strike = $word->parameter;
		if($word->word == "v") $this->state->hidden = $word->parameter;
		if($word->word == "fs") $this->state->fontsize = ceil(($word->parameter / 24) * 16);
		if($word->word == "par") $this->state->par = true;
		
		// Characters:
		if($word->word == "lquote") $this->output .= "&lsquo;";
		if($word->word == "rquote") $this->output .= "&rsquo;";
		if($word->word == "ldblquote") $this->output .= "&ldquo;";
		if($word->word == "rdblquote") $this->output .= "&rdquo;";
		if($word->word == "emdash") $this->output .= "&mdash;";
		if($word->word == "endash") $this->output .= "&ndash;";
		if($word->word == "bullet") $this->output .= "&bull;";
		if($word->word == "u") $this->output .= "&loz;";
	}
 
	protected function BeginState()
	{
		$span = "";
		if($this->state->bold) $span .= " font-weight:bold;";
		if($this->state->italic) $span .= " font-style:italic;";
		if($this->state->underline) $span .= " text-decoration:underline;";
		if($this->state->end_underline) $span .= " text-decoration:none;";
		if($this->state->strike) $span .= " text-decoration:strikethrough;";
		if($this->state->hidden) $span .= " display:none;";
		if($this->state->fontsize != 0) $span .= " font-size: {$this->state->fontsize}px;";
		$this->output .= "<span style='{$span}'>";
		if($this->state->par)
			$this->output .= '<p>';
	}
 
	protected function EndState()
	{
		if($this->state->par)
			$this->output .= '</p>';
		$this->output .= "</span>";
	}
 
	protected function FormatControlSymbol($symbol)
	{
		if($symbol->symbol == '\'')
		{
		$this->BeginState();
		$this->output .= htmlentities(chr($symbol->parameter), ENT_QUOTES, 'ISO-8859-1');
		$this->EndState();
		}
	}
 
	protected function FormatText($text)
	{
		$this->BeginState();
		$this->output .= $text->text;
		$this->EndState();
	}
}

class RtfTableState
{
	public function __construct()
	{
		$this->Reset();
	}
 
	public function Reset()
	{
		$this->in_table = false;
		$this->row_start = false;
		$this->row_end = false;
		$this->headers_start = false;
		$this->headers_end = false;
		
		$this->class = array();
	}
}

class RtfTables
{
	public function Format($root)
	{
		$this->tables = array();
		$this->rowMatrix = array();
		$this->tableIdx = 0;
		$this->rowIdx = 0;
		$this->cellIdx = 0;
		
		// Create a stack of states:
		$this->states = array();
		// Put an initial standard state onto the stack:
		$this->state = new RtfTableState();
		array_push($this->states, $this->state);
		
		$this->FormatGroup($root);
		return $this->tables;
	}
 
	protected function FormatGroup($group)
	{
		// Can we ignore this group?
		if ($group->GetType() == "fonttbl") return;
		if ($group->GetType() == "colortbl") return;
		if ($group->GetType() == "stylesheet") return;
		if ($group->GetType() == "info") return;
		// Skip any pictures:
		if (substr($group->GetType(), 0, 4) == "pict") return;
		if ($group->IsDestination()) return;
 
		// Push a new state onto the stack:
		$this->state = clone $this->state;
		array_push($this->states, $this->state);
 
		foreach($group->children as $child)
		{
			if(get_class($child) == "RtfGroup") $this->FormatGroup($child);
			if(get_class($child) == "RtfControlWord") $this->FormatControlWord($child);
			if(get_class($child) == "RtfControlSymbol") $this->FormatControlSymbol($child);
			if(get_class($child) == "RtfText") $this->FormatText($child);
		}
 
		// Pop state from stack.
		array_pop($this->states);
		$this->state = $this->states[sizeof($this->states)-1];
	}
 
	protected function FormatControlWord($word)
	{
		if($word->word == "plain") $this->state->Reset();
		if($word->word == "intbl") $this->state->in_table = true;
		
		if($word->word == 'trowd') // start new row
		{
			$this->state->row_start = true;
			$this->state->row_end = false;
			$this->state->in_table = true; 
		}
		
		if($word->word == 'trhdr') // start header row
		{
			$this->tableIdx++;
			$this->rowIdx = 0;
			$this->cellIdx = 0;
			$this->state->headers_start = true;
			$this->state->headers_end = false;
		}
		
		if($word->word == 'row')  // end row
		{
			if(!$this->state->headers_start)
				foreach($this->cellMatrix as $matrixSlug)
					if(!isset($this->tables[$this->tableIdx][$this->rowIdx][$matrixSlug]))
						$this->tables[$this->tableIdx][$this->rowIdx][$matrixSlug] = false;
					
			$this->cellIdx = 0;
			$this->rowIdx++;
			
			$this->state->headers_start = false;
			$this->state->headers_end = true; 
			$this->state->row_end = true;
		}
		
		if($word->word == 'cell')  // end cell
		{
			$this->cellIdx++;
		}
	}
 
	protected function BeginState()
	{
	}
 
	protected function EndState($text = false)
	{
		if($this->state->headers_start and !$this->state->headers_end)
		{
			$slug = $this->slug($text->text);
			$this->cellMatrix[$this->cellIdx] = $slug;
		}
		
		if($this->state->in_table and !$this->state->headers_start)
			$this->tables[$this->tableIdx][$this->rowIdx][$this->cellMatrix[$this->cellIdx]] = $text->text;
	}
 
	protected function FormatControlSymbol($symbol)
	{
		if($symbol->symbol == '\'')
		{
		$this->BeginState();
		$this->output .= htmlentities(chr($symbol->parameter), ENT_QUOTES, 'ISO-8859-1');
		$this->EndState();
		}
	}
 
	protected function FormatText($text)
	{
		$this->BeginState();
		$text->text = trim($text->text);
		$this->EndState($text);
	}
	
	protected function slug($string, $replacement = '_')
	{
		if(is_string($string))
			$string = strtolower($string);
		
		$quotedReplacement = preg_quote($replacement, '/');

		$merge = array(
			'/[^\s\p{Zs}\p{Ll}\p{Lm}\p{Lo}\p{Lt}\p{Lu}\p{Nd}]/mu' => ' ',
			'/[\s\p{Zs}]+/mu' => $replacement,
			sprintf('/^[%s]+|[%s]+$/', $quotedReplacement, $quotedReplacement) => '',
		);
		
		$map = $this->_transliteration + $merge;
		return preg_replace(array_keys($map), array_values($map), $string);
	}

/**
 * Default map of accented and special characters to ASCII characters
 *
 * @var array
 */
	protected $_transliteration = array(
		'/À|Á|Â|Ã|Å|Ǻ|Ā|Ă|Ą|Ǎ/' => 'A',
		'/Æ|Ǽ/' => 'AE',
		'/Ä/' => 'Ae',
		'/Ç|Ć|Ĉ|Ċ|Č/' => 'C',
		'/Ð|Ď|Đ/' => 'D',
		'/È|É|Ê|Ë|Ē|Ĕ|Ė|Ę|Ě/' => 'E',
		'/Ĝ|Ğ|Ġ|Ģ|Ґ/' => 'G',
		'/Ĥ|Ħ/' => 'H',
		'/Ì|Í|Î|Ï|Ĩ|Ī|Ĭ|Ǐ|Į|İ|І/' => 'I',
		'/Ĳ/' => 'IJ',
		'/Ĵ/' => 'J',
		'/Ķ/' => 'K',
		'/Ĺ|Ļ|Ľ|Ŀ|Ł/' => 'L',
		'/Ñ|Ń|Ņ|Ň/' => 'N',
		'/Ò|Ó|Ô|Õ|Ō|Ŏ|Ǒ|Ő|Ơ|Ø|Ǿ/' => 'O',
		'/Œ/' => 'OE',
		'/Ö/' => 'Oe',
		'/Ŕ|Ŗ|Ř/' => 'R',
		'/Ś|Ŝ|Ş|Ș|Š/' => 'S',
		'/ẞ/' => 'SS',
		'/Ţ|Ț|Ť|Ŧ/' => 'T',
		'/Þ/' => 'TH',
		'/Ù|Ú|Û|Ũ|Ū|Ŭ|Ů|Ű|Ų|Ư|Ǔ|Ǖ|Ǘ|Ǚ|Ǜ/' => 'U',
		'/Ü/' => 'Ue',
		'/Ŵ/' => 'W',
		'/Ý|Ÿ|Ŷ/' => 'Y',
		'/Є/' => 'Ye',
		'/Ї/' => 'Yi',
		'/Ź|Ż|Ž/' => 'Z',
		'/à|á|â|ã|å|ǻ|ā|ă|ą|ǎ|ª/' => 'a',
		'/ä|æ|ǽ/' => 'ae',
		'/ç|ć|ĉ|ċ|č/' => 'c',
		'/ð|ď|đ/' => 'd',
		'/è|é|ê|ë|ē|ĕ|ė|ę|ě/' => 'e',
		'/ƒ/' => 'f',
		'/ĝ|ğ|ġ|ģ|ґ/' => 'g',
		'/ĥ|ħ/' => 'h',
		'/ì|í|î|ï|ĩ|ī|ĭ|ǐ|į|ı|і/' => 'i',
		'/ĳ/' => 'ij',
		'/ĵ/' => 'j',
		'/ķ/' => 'k',
		'/ĺ|ļ|ľ|ŀ|ł/' => 'l',
		'/ñ|ń|ņ|ň|ŉ/' => 'n',
		'/ò|ó|ô|õ|ō|ŏ|ǒ|ő|ơ|ø|ǿ|º/' => 'o',
		'/ö|œ/' => 'oe',
		'/ŕ|ŗ|ř/' => 'r',
		'/ś|ŝ|ş|ș|š|ſ/' => 's',
		'/ß/' => 'ss',
		'/ţ|ț|ť|ŧ/' => 't',
		'/þ/' => 'th',
		'/ù|ú|û|ũ|ū|ŭ|ů|ű|ų|ư|ǔ|ǖ|ǘ|ǚ|ǜ/' => 'u',
		'/ü/' => 'ue',
		'/ŵ/' => 'w',
		'/ý|ÿ|ŷ/' => 'y',
		'/є/' => 'ye',
		'/ї/' => 'yi',
		'/ź|ż|ž/' => 'z',
	);
}
