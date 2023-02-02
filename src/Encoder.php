<?php

namespace Semji\GPT3Tokenizer;

class Encoder
{
    public function encode(string $text)
    {
        $bpe_tokens = [];
        if (empty($text)) {
            return $bpe_tokens;
        }

        $raw_chars = file_get_contents(__DIR__.'/../data/characters.json');
        $byte_encoder = json_decode($raw_chars, true, 512, JSON_THROW_ON_ERROR);
        if (empty($byte_encoder)) {
            return $bpe_tokens;
        }

        $rencoder = file_get_contents(__DIR__.'/../data/encoder.json');
        $encoder = json_decode($rencoder, true, 512, JSON_THROW_ON_ERROR);
        if (empty($encoder)) {
            return $bpe_tokens;
        }

        $bpe_file = file_get_contents(__DIR__.'/../data/vocab.bpe');
        if (empty($bpe_file)) {
            return $bpe_tokens;
        }

        preg_match_all("#'s|'t|'re|'ve|'m|'ll|'d| ?\p{L}+| ?\p{N}+| ?[^\s\p{L}\p{N}]+|\s+(?!\S)|\s+#u", $text, $matches);
        if (!isset($matches[0]) || 0 == (is_countable($matches[0]) ? count($matches[0]) : 0)) {
            error_log('Failed to match string: '.$text);

            return $bpe_tokens;
        }

        $lines = preg_split('#\r\n|\r|\n#', $bpe_file);
        $bpe_merges = [];
        $bpe_merges_temp = array_slice($lines, 1, is_countable($lines) ? count($lines) : 0, true);
        foreach ($bpe_merges_temp as $bmt) {
            $split_bmt = preg_split('#(\s+)#', (string) $bmt);
            $split_bmt = array_filter($split_bmt, $this->my_filter(...));
            if ($split_bmt !== []) {
                $bpe_merges[] = $split_bmt;
            }
        }

        $bpe_ranks = $this->dictZip($bpe_merges, range(0, count($bpe_merges) - 1));

        $cache = [];
        foreach ($matches[0] as $token) {
            $chars = [];
            $token = utf8_encode((string) $token);
            $len = mb_strlen($token, 'UTF-8');
            for ($i = 0; $i < $len; ++$i) {
                $chars[] = mb_substr($token, $i, 1, 'UTF-8');
            }

            $result_word = '';
            foreach ($chars as $char) {
                if (isset($byte_encoder[$this->unichr($char)])) {
                    $result_word .= $byte_encoder[$this->unichr($char)];
                }
            }

            $new_tokens_bpe = $this->bpe($result_word, $bpe_ranks, $cache);
            $new_tokens_bpe = explode(' ', (string) $new_tokens_bpe);
            foreach ($new_tokens_bpe as $newBpeToken) {
                $encoded = $encoder[$newBpeToken] ?? $newBpeToken;
                if (isset($bpe_tokens[$newBpeToken])) {
                    $bpe_tokens[] = $encoded;
                } else {
                    $bpe_tokens[$newBpeToken] = $encoded;
                }
            }
        }

        return array_values($bpe_tokens);
    }

    private function my_filter($var)
    {
        return null !== $var && false !== $var && '' !== $var;
    }

    private function unichr($c)
    {
        if (ord($c[0]) >= 0 && ord($c[0]) <= 127) {
            return ord($c[0]);
        }

        if (ord($c[0]) >= 192 && ord($c[0]) <= 223) {
            return (ord($c[0]) - 192) * 64 + (ord($c[1]) - 128);
        }

        if (ord($c[0]) >= 224 && ord($c[0]) <= 239) {
            return (ord($c[0]) - 224) * 4096 + (ord($c[1]) - 128) * 64 + (ord($c[2]) - 128);
        }

        if (ord($c[0]) >= 240 && ord($c[0]) <= 247) {
            return (ord($c[0]) - 240) * 262144 + (ord($c[1]) - 128) * 4096 + (ord($c[2]) - 128) * 64 + (ord($c[3]) - 128);
        }

        if (ord($c[0]) >= 248 && ord($c[0]) <= 251) {
            return (ord($c[0]) - 248) * 16_777_216 + (ord($c[1]) - 128) * 262144 + (ord($c[2]) - 128) * 4096 + (ord($c[3]) - 128) * 64 + (ord($c[4]) - 128);
        }

        if (ord($c[0]) >= 252 && ord($c[0]) <= 253) {
            return (ord($c[0]) - 252) * 1_073_741_824 + (ord($c[1]) - 128) * 16_777_216 + (ord($c[2]) - 128) * 262144 + (ord($c[3]) - 128) * 4096 + (ord($c[4]) - 128) * 64 + (ord($c[5]) - 128);
        }

        if (ord($c[0]) >= 254 && ord($c[0]) <= 255) {
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

    private function get_pairs($word)
    {
        $pairs = [];
        $prev_char = $word[0];
        for ($i = 1; $i < (is_countable($word) ? count($word) : 0); ++$i) {
            $char = $word[$i];
            $pairs[] = [$prev_char, $char];
            $prev_char = $char;
        }

        return $pairs;
    }

    private function split($str, $len = 1)
    {
        $arr = [];
        $length = mb_strlen((string) $str, 'UTF-8');

        for ($i = 0; $i < $length; $i += $len) {
            $arr[] = mb_substr((string) $str, $i, $len, 'UTF-8');
        }

        return $arr;
    }

    private function bpe($token, $bpe_ranks, &$cache)
    {
        if (array_key_exists($token, $cache)) {
            return $cache[$token];
        }

        $word = $this->split($token);
        $init_len = is_countable($word) ? count($word) : 0;
        $pairs = $this->get_pairs($word);
        if (!$pairs) {
            return $token;
        }

        while (true) {
            $minPairs = [];
            foreach ($pairs as $pair) {
                if (array_key_exists($pair[0].','.$pair[1], $bpe_ranks)) {
                    $rank = $bpe_ranks[$pair[0].','.$pair[1]];
                    $minPairs[$rank] = $pair;
                } else {
                    $minPairs[10e10] = $pair;
                }
            }

            ksort($minPairs);
            $min_key = array_key_first($minPairs);
            foreach ($minPairs as $mpi => $mp) {
                if ($mpi < $min_key) {
                    $min_key = $mpi;
                }
            }

            $bigram = $minPairs[$min_key];
            if (!array_key_exists($bigram[0].','.$bigram[1], $bpe_ranks)) {
                break;
            }

            $first = $bigram[0];
            $second = $bigram[1];
            $new_word = [];
            $i = 0;
            while ($i < (is_countable($word) ? count($word) : 0)) {
                $j = $this->indexOf($word, $first, $i);
                if (-1 === $j) {
                    $new_word = array_merge($new_word, array_slice($word, $i, null, true));
                    break;
                }

                if ($i > $j) {
                    $slicer = [];
                } elseif (0 == $j) {
                    $slicer = [];
                } else {
                    $slicer = array_slice($word, $i, $j - $i, true);
                }

                $new_word = array_merge($new_word, $slicer);
                if (count($new_word) > $init_len) {
                    break;
                }

                $i = $j;
                if ($word[$i] === $first && $i < (is_countable($word) ? count($word) : 0) - 1 && $word[$i + 1] === $second) {
                    array_push($new_word, $first.$second);
                    $i = $i + 2;
                } else {
                    array_push($new_word, $word[$i]);
                    $i = $i + 1;
                }
            }

            if ($word == $new_word) {
                break;
            }

            $word = $new_word;
            if (1 === count($word)) {
                break;
            } else {
                $pairs = $this->get_pairs($word);
            }
        }

        $word = implode(' ', $word);
        $cache[$token] = $word;

        return $word;
    }

    private function indexOf($array, $searchElement, $fromIndex)
    {
        $index = 0;
        foreach ($array as $index => $value) {
            if ($index < $fromIndex) {
                ++$index;
                continue;
            }

            if ($value == $searchElement) {
                return $index;
            }

            ++$index;
        }

        return -1;
    }
}
