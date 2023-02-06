# GPT3 tokenizer

PHP Text Tokenizer for GPT models

## About

A PHP toolkit to tokenize text like GPT family of models process it.

Forked from https://github.com/CodeRevolutionPlugins/GPT-3-Encoder-PHP to fit our usage, fix bugs and add unit testing.

## Usage

The mbstring PHP extension is needed for this tool to work correctly (in case non-ASCII characters are present in the tokenized text): [details here on how to install mbstring](https://www.php.net/manual/en/mbstring.installation.php)
PHP 8.1 is needed too;

```php
use Semji\GPT3Tokenizer\Encoder;
$prompt = "Many words map";
$encoder = new Encoder();
$encoder->encode($prompt);
```
