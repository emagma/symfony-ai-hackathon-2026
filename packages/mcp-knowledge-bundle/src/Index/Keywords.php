<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Index;

/**
 * The words of a question worth searching for: the full-text index matches any of them and ranks by them.
 *
 * @internal
 */
final class Keywords
{
    public const int MAX = 12;

    private const array STOPWORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her', 'was', 'one', 'our', 'out', 'has', 'have',
        'what', 'when', 'where', 'which', 'who', 'why', 'how', 'does', 'did', 'this', 'that', 'with', 'from', 'they',
        'will', 'would', 'should', 'could', 'about', 'there', 'their', 'into', 'than', 'then', 'them', 'been', 'being',
        'les', 'des', 'une', 'que', 'qui', 'quoi', 'est', 'pour', 'dans', 'sur', 'avec', 'pas', 'faire', 'fait',
        'comment', 'quand', 'quel', 'quelle', 'nous', 'vous', 'son', 'ses', 'aux', 'par', 'plus',
    ];

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function of(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && !\in_array($word, self::STOPWORDS, true)) {
                $kept[$word] = true;
            }
        }

        return \array_slice(array_keys($kept), 0, self::MAX);
    }
}
