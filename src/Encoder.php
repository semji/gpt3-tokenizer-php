<?php

namespace Semji\GPT3Tokenizer;

class Encoder
{
    public function encode(string $text)
    {
        if (empty($text)) {
            return [];
        }

        $rawCharacters = json_decode(file_get_contents(__DIR__.'/../data/characters.json'), true, 512, JSON_THROW_ON_ERROR);
        if (empty($rawCharacters)) {
            return [];
        }

        $encoder = json_decode(file_get_contents(__DIR__.'/../data/encoder.json'), true, 512, JSON_THROW_ON_ERROR);
        if (empty($encoder)) {
            return [];
        }

        $bpeDictionary = file_get_contents(__DIR__.'/../data/vocab.bpe');
        if (empty($bpeDictionary)) {
            return [];
        }

        preg_match_all("#'s|'t|'re|'ve|'m|'ll|'d| ?\p{L}+| ?\p{N}+| ?[^\s\p{L}\p{N}]+|\s+(?!\S)|\s+#u", $text, $matches);
        if (!isset($matches[0]) || 0 == (is_countable($matches[0]) ? count($matches[0]) : 0)) {
            return [];
        }

        $bpeTokens = [];
        $lines = preg_split('#\r\n|\r|\n#', $bpeDictionary);
        $bpeMerges = [];
        $rawDictionaryLines = array_slice($lines, 1, is_countable($lines) ? count($lines) : 0, true);
        foreach ($rawDictionaryLines as $rawDictionaryLine) {
            $splitLine = preg_split('#(\s+)#', (string) $rawDictionaryLine);
            $splitLine = array_filter($splitLine, $this->filterEmpty(...));
            if ([] !== $splitLine) {
                $bpeMerges[] = $splitLine;
            }
        }

        $bpeRanks = $this->dictZip($bpeMerges, range(0, count($bpeMerges) - 1));

        $cache = [];
        foreach ($matches[0] as $token) {
            $chars = [];
            $token = utf8_encode((string) $token);
            $len = mb_strlen($token, 'UTF-8');
            for ($i = 0; $i < $len; ++$i) {
                $chars[] = mb_substr($token, $i, 1, 'UTF-8');
            }

            $resultWord = '';
            foreach ($chars as $char) {
                if (isset($rawCharacters[$this->characterToUnicode($char)])) {
                    $resultWord .= $rawCharacters[$this->characterToUnicode($char)];
                }
            }

            $newTokensBpe = $this->bpe($resultWord, $bpeRanks, $cache);
            $newTokensBpe = explode(' ', (string) $newTokensBpe);
            foreach ($newTokensBpe as $newBpeToken) {
                $encoded = $encoder[$newBpeToken] ?? $newBpeToken;
                if (isset($bpeTokens[$newBpeToken])) {
                    $bpeTokens[] = $encoded;
                } else {
                    $bpeTokens[$newBpeToken] = $encoded;
                }
            }
        }

        return array_values($bpeTokens);
    }

    private function filterEmpty($var): bool
    {
        return null !== $var && false !== $var && '' !== $var;
    }

    private function characterToUnicode($characters): int
    {
        $firstCharacterCode = ord($characters[0]);

        if ($firstCharacterCode <= 127) {
            return $firstCharacterCode;
        }

        if ($firstCharacterCode >= 192 && $firstCharacterCode <= 223) {
            return ($firstCharacterCode - 192) * 64 + (ord($characters[1]) - 128);
        }

        if ($firstCharacterCode >= 224 && $firstCharacterCode <= 239) {
            return ($firstCharacterCode - 224) * 4096 + (ord($characters[1]) - 128) * 64 + (ord($characters[2]) - 128);
        }

        if ($firstCharacterCode >= 240 && $firstCharacterCode <= 247) {
            return ($firstCharacterCode - 240) * 262144 + (ord($characters[1]) - 128) * 4096 + (ord($characters[2]) - 128) * 64 + (ord($characters[3]) - 128);
        }

        if ($firstCharacterCode >= 248 && $firstCharacterCode <= 251) {
            return ($firstCharacterCode - 248) * 16_777_216 + (ord($characters[1]) - 128) * 262144 + (ord($characters[2]) - 128) * 4096 + (ord($characters[3]) - 128) * 64 + (ord($characters[4]) - 128);
        }

        if ($firstCharacterCode >= 252 && $firstCharacterCode <= 253) {
            return ($firstCharacterCode - 252) * 1_073_741_824 + (ord($characters[1]) - 128) * 16_777_216 + (ord($characters[2]) - 128) * 262144 + (ord($characters[3]) - 128) * 4096 + (ord($characters[4]) - 128) * 64 + (ord($characters[5]) - 128);
        }

        if ($firstCharacterCode >= 254) {
            return 0;
        }

        return 0;
    }

    private function dictZip($x, $y)
    {
        $result = [];
        $cnt = 0;
        foreach ($x as $i) {
            if (isset($i[1]) && isset($i[0])) {
                $result[$i[0].','.$i[1]] = $cnt;
                ++$cnt;
            }
        }

        return $result;
    }

    /**n
     * Return set of symbol pairs in a word.
     * Word is represented as tuple of symbols (symbols being variable-length strings).
     */
    private function buildSymbolPairs(array $word): array
    {
        $pairs = [];
        $previousPart = null;
        foreach ($word as $i => $part) {
            if ($i > 0) {
                $pairs[] = [$previousPart, $part];
            }

            $previousPart = $part;
        }

        return $pairs;
    }

    private function splitWord(string $word, $len = 1): array
    {
        $splitWord = [];
        $length = mb_strlen($word, 'UTF-8');

        for ($i = 0; $i < $length; $i += $len) {
            $splitWord[] = mb_substr($word, $i, $len, 'UTF-8');
        }

        return $splitWord;
    }

    private function bpe($token, $bpeRanks, &$cache)
    {
        if (array_key_exists($token, $cache)) {
            return $cache[$token];
        }

        $word = $this->splitWord($token);
        $initialLength = count($word);
        $pairs = $this->buildSymbolPairs($word);
        if ($pairs === []) {
            return $token;
        }

        while (true) {
            $minPairs = [];
            foreach ($pairs as $pair) {
                if (array_key_exists($pair[0].','.$pair[1], $bpeRanks)) {
                    $rank = $bpeRanks[$pair[0].','.$pair[1]];
                    $minPairs[$rank] = $pair;
                } else {
                    $minPairs[10e10] = $pair;
                }
            }

            ksort($minPairs);
            $minimumKey = array_key_first($minPairs);
            foreach (array_keys($minPairs) as $minPairIndex) {
                if ($minPairIndex < $minimumKey) {
                    $minimumKey = $minPairIndex;
                }
            }

            $bigram = $minPairs[$minimumKey];
            if (!array_key_exists($bigram[0].','.$bigram[1], $bpeRanks)) {
                break;
            }

            $first = $bigram[0];
            $second = $bigram[1];
            $newWord = [];
            $i = 0;
            while ($i < count($word)) {
                $j = $this->indexOf($word, $first, $i);
                if (-1 === $j) {
                    $newWord = array_merge($newWord, array_slice($word, $i, null, true));
                    break;
                }

                if ($i > $j) {
                    $slicer = [];
                } elseif (0 == $j) {
                    $slicer = [];
                } else {
                    $slicer = array_slice($word, $i, $j - $i, true);
                }

                $newWord = array_merge($newWord, $slicer);
                if (count($newWord) > $initialLength) {
                    break;
                }

                $i = $j;
                if ($word[$i] === $first && $i < count($word) - 1 && $word[$i + 1] === $second) {
                    $newWord[] = $first.$second;
                    $i += 2;
                } else {
                    $newWord[] = $word[$i];
                    ++$i;
                }
            }

            if ($word === $newWord) {
                break;
            }

            $word = $newWord;
            if (1 === count($word)) {
                break;
            }

            $pairs = $this->buildSymbolPairs($word);
        }

        $word = implode(' ', $word);
        $cache[$token] = $word;

        return $word;
    }

    private function indexOf(array $array, $searchElement, $fromIndex): int
    {
        foreach ($array as $index => $value) {
            if ($index < $fromIndex) {
//                ++$index;
                continue;
            }

            if ($value == $searchElement) {
                return $index;
            }
//            ++$index;
        }

        return -1;
    }
}
