<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Verifies how a .env value is read.
 *
 * The case that matters most here is the one that shipped broken: a key with
 * no value and an explanatory comment after it. Trimming removes the space the
 * trailing-comment test depends on, so the comment text became the value, and
 * a blank DB_TIMEZONE was sent to MySQL as a time zone — reported to the
 * operator as nothing more than "connect failed".
 *
 * @package Tests\Unit
 * @version 1.0.0
 */
final class EnvParsingTest extends TestCase
{
    private ReflectionMethod $normalise;

    public function description(): string
    {
        return 'Reading values, comments and types out of a .env file';
    }

    public function setUp(): void
    {
        $this->normalise = new ReflectionMethod(Env::class, 'normalise');
        $this->normalise->setAccessible(true);
    }

    private function read(string $raw): mixed
    {
        return $this->normalise->invoke(null, $raw);
    }

    // ------------------------------------------------------------------
    // Comments
    // ------------------------------------------------------------------

    public function testAValueThatIsOnlyACommentIsEmpty(): void
    {
        $this->assertSame(
            '',
            $this->read('              # blank derives the offset from APP_TIMEZONE'),
            'a key with no value and a trailing comment reads as empty'
        );
    }

    public function testACommentWithNoLeadingWhitespaceIsStillAComment(): void
    {
        $this->assertSame('', $this->read('# nothing here'), 'a value beginning with a hash reads as empty');
    }

    public function testATrailingCommentIsRemoved(): void
    {
        $this->assertSame(1800, $this->read('1800        # seconds of inactivity'), 'the comment does not reach the value');
    }

    public function testATabAlignedCommentIsRemoved(): void
    {
        $this->assertSame('Lax', $this->read("Lax\t# cross-site policy"), 'a tab before the hash separates a comment too');
    }

    public function testAHashInsideAValueIsNotAComment(): void
    {
        $this->assertSame(
            'http://vams.local/#dashboard',
            $this->read('http://vams.local/#dashboard'),
            'a fragment in a URL survives'
        );
    }

    public function testAQuotedValueMayBeginWithAHash(): void
    {
        $this->assertSame('#ff0000', $this->read('"#ff0000"'), 'quoting is how a literal hash is written');
    }

    public function testAQuotedValueKeepsItsComment(): void
    {
        $this->assertSame('a # b', $this->read('"a # b"'), 'nothing inside quotes is treated as a comment');
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    public function testBooleansAreCast(): void
    {
        $this->assertSame(true, $this->read('true'), 'true becomes a boolean');
        $this->assertSame(false, $this->read('false'), 'false becomes a boolean');
    }

    public function testNumbersAreCast(): void
    {
        $this->assertSame(3306, $this->read('3306'), 'an integer becomes an int');
        $this->assertSame(1.5, $this->read('1.5'), 'a decimal becomes a float');
    }

    public function testNullAndEmptyKeywords(): void
    {
        $this->assertNull($this->read('null'), 'null becomes null');
        $this->assertSame('', $this->read('empty'), 'empty becomes an empty string');
    }

    public function testATimeZoneNameIsLeftAlone(): void
    {
        $this->assertSame('Asia/Manila', $this->read('Asia/Manila'), 'a zone name is not mangled');
    }

    public function testABlankValueIsEmpty(): void
    {
        $this->assertSame('', $this->read(''), 'a key with nothing after the equals reads as empty');
        $this->assertSame('', $this->read('     '), 'whitespace alone reads as empty');
    }

    /**
     * A password is the value most likely to contain characters the parser
     * might mishandle, and the one where mishandling is worst.
     */
    public function testAPasswordWithPunctuationSurvives(): void
    {
        $this->assertSame(
            'aB3$xY!z-Q7%wR2=',
            $this->read('aB3$xY!z-Q7%wR2='),
            'punctuation, including an equals sign, is preserved'
        );
    }
}
