<?php

namespace App\Services;

class TextChunker
{
    /** @return list<string> */
    public function split(string $text): array
    {
        $text = trim(preg_replace("/\r\n?/", "\n", $text) ?? $text);
        $size = max(200, (int) config('services.ai.chunk_size'));
        $overlap = min(max(0, (int) config('services.ai.chunk_overlap')), $size - 1);
        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunk = trim(mb_substr($text, $offset, $size));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            $offset += $size - $overlap;
        }

        return $chunks;
    }
}
