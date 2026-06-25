<?php

namespace Drupal\Tests\issue_analysis\Unit\Service;

use Drupal\issue_analysis\Service\AiSummariserService;
use Drupal\issue_analysis\Service\DailyDigestService;
use Drupal\issue_analysis\Service\NewsletterDataFetcherService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Unit tests for name-free attribution in DailyDigestService.
 *
 * Covers buildContributors() (who is "active in the period") and the
 * name-leak backstop (findNameLeaks / stripNameAttribution).
 */
#[Group('issue_analysis')]
class DigestContributorsTest extends TestCase {

  use ProphecyTrait;

  private const SINCE = '2026-06-24T00:00:00Z';
  private const UNTIL = '2026-06-25T00:00:00Z';

  /**
   * Builds a DailyDigestService with mocked dependencies.
   */
  private function makeService(?AiSummariserService $summariser = NULL): DailyDigestService {
    $fetcher = $this->prophesize(NewsletterDataFetcherService::class)->reveal();
    $summariser = $summariser ?? $this->prophesize(AiSummariserService::class)->reveal();
    $state = $this->prophesize(StateInterface::class)->reveal();
    $etm = $this->prophesize(EntityTypeManagerInterface::class)->reveal();
    $channel = $this->prophesize(LoggerChannelInterface::class)->reveal();
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get(\Prophecy\Argument::any())->willReturn($channel);
    return new DailyDigestService($fetcher, $summariser, $state, $etm, $loggerFactory->reveal());
  }

  /**
   * Makes a summariser mock whose complete() returns the given string.
   */
  private function summariserReturning(string $response): AiSummariserService {
    $s = $this->prophesize(AiSummariserService::class);
    $s->complete(\Prophecy\Argument::cetera())->willReturn($response);
    return $s->reveal();
  }

  // ---------------------------------------------------------------------------
  // buildContributors()
  // ---------------------------------------------------------------------------

  public function testInWindowCommentAuthorIsIncluded(): void {
    $module = [
      'issues' => [
        [
          'comments' => [
            ['author' => 'alice', 'author_name' => 'Alice Smith', 'created_at' => '2026-06-24T10:00:00Z'],
          ],
        ],
      ],
    ];
    $result = $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL);
    $this->assertSame(['Alice Smith (alice)'], $result);
  }

  public function testCommentOutsideWindowIsExcluded(): void {
    $module = [
      'issues' => [
        [
          'comments' => [
            ['author' => 'bob', 'author_name' => 'Bob Jones', 'created_at' => '2026-06-01T10:00:00Z'],
          ],
        ],
      ],
    ];
    $this->assertSame([], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testStaleAssigneeWithNoActivityIsExcluded(): void {
    // The issue has an assignee but no in-window activity by them.
    $module = [
      'issues' => [
        [
          'author' => 'carol',
          'author_name' => 'Carol',
          'assignees' => ['dave'],
          'assignee_names' => ['Dave'],
          'comments' => [],
        ],
      ],
    ];
    $this->assertSame([], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testMergedMrAuthorInWindowIsIncluded(): void {
    $module = [
      'merge_requests' => [
        ['author' => 'eve', 'author_name' => 'Eve', 'merged_at' => '2026-06-24T12:00:00Z', 'created_at' => '2026-05-01T00:00:00Z'],
      ],
    ];
    $this->assertSame(['Eve (eve)'], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testCommitAuthorInWindowIsIncludedByNameWithCount(): void {
    $module = [
      'commits' => [
        ['author_name' => 'Frank', 'authored_date' => '2026-06-24T09:00:00Z'],
      ],
    ];
    $this->assertSame(['Frank [1]'], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testCommitCountAccumulatesPerPerson(): void {
    $module = [
      'commits' => [
        ['author_name' => 'Grace', 'authored_date' => '2026-06-24T09:00:00Z'],
        ['author_name' => 'Grace', 'authored_date' => '2026-06-24T10:00:00Z'],
        ['author_name' => 'Grace', 'authored_date' => '2026-06-24T11:00:00Z'],
      ],
    ];
    $this->assertSame(['Grace [3]'], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testContributorWithNoCommitsHasNoBracket(): void {
    // Active via a comment only — no commit, so no "[N]" suffix.
    $module = [
      'issues' => [
        ['comments' => [['author' => 'heidi', 'author_name' => 'Heidi', 'created_at' => '2026-06-24T10:00:00Z']]],
      ],
    ];
    $this->assertSame(['Heidi (heidi)'], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testCommitCountFollowsUsernameFoldRegardlessOfOrder(): void {
    // Commit (name only) before the MR that supplies the username: the count
    // must attach to the merged "Name (username)" entry.
    $module = [
      'commits' => [
        ['author_name' => 'Ivan', 'authored_date' => '2026-06-24T10:00:00Z'],
        ['author_name' => 'Ivan', 'authored_date' => '2026-06-24T11:00:00Z'],
      ],
      'merge_requests' => [
        ['author' => 'ivan', 'author_name' => 'Ivan', 'merged_at' => '2026-06-24T12:00:00Z'],
      ],
    ];
    $this->assertSame(['Ivan (ivan) [2]'], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testPersonCountedOnceAcrossSources(): void {
    $module = [
      'issues' => [
        ['comments' => [['author' => 'alice', 'author_name' => 'Alice Smith', 'created_at' => '2026-06-24T10:00:00Z']]],
      ],
      'merge_requests' => [
        ['author' => 'alice', 'author_name' => 'Alice Smith', 'merged_at' => '2026-06-24T11:00:00Z'],
      ],
    ];
    $this->assertSame(['Alice Smith (alice)'], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testConfidentialIssueCommentsAreSkipped(): void {
    $module = [
      'issues' => [
        [
          'confidential' => TRUE,
          'comments' => [['author' => 'secret', 'author_name' => 'Secret', 'created_at' => '2026-06-24T10:00:00Z']],
        ],
      ],
    ];
    $this->assertSame([], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  public function testResultIsSortedAndDeduplicated(): void {
    $module = [
      'issues' => [
        ['comments' => [['author' => 'zoe', 'author_name' => 'Zoe', 'created_at' => '2026-06-24T10:00:00Z']]],
      ],
      'merge_requests' => [
        ['author' => 'amy', 'author_name' => 'Amy', 'merged_at' => '2026-06-24T11:00:00Z'],
      ],
      'commits' => [
        ['author_name' => 'Amy', 'authored_date' => '2026-06-24T10:00:00Z'],
      ],
    ];
    $result = $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL);
    // Amy (amy) sorts before Zoe; commit "Amy" must not double-count, and her
    // single commit shows as "[1]". Zoe has no commit, so no bracket.
    $this->assertSame(['Amy (amy) [1]', 'Zoe (zoe)'], $result);
  }

  public function testNameOnlyCommitFoldsIntoUsernameRegardlessOfOrder(): void {
    // Commit (name only) appears in data BEFORE the MR that carries the
    // username — the two must still collapse to one entry.
    $module = [
      'commits' => [
        ['author_name' => 'Amy', 'authored_date' => '2026-06-24T10:00:00Z'],
      ],
      'merge_requests' => [
        ['author' => 'amy', 'author_name' => 'Amy', 'merged_at' => '2026-06-24T11:00:00Z'],
      ],
    ];
    $this->assertSame(['Amy (amy) [1]'], $this->makeService()->buildContributors($module, self::SINCE, self::UNTIL));
  }

  // ---------------------------------------------------------------------------
  // findNameLeaks()
  // ---------------------------------------------------------------------------

  public function testNameInProseIsFlagged(): void {
    $html = '<p>Alice Smith merged the fix.</p><p>Contributors: Alice Smith (alice)</p>';
    $leaks = $this->makeService()->findNameLeaks($html, ['Alice Smith (alice)']);
    $this->assertContains('Alice Smith', $leaks);
  }

  public function testUsernameInProseIsFlagged(): void {
    $html = '<p>The fix from alice was merged.</p><p>Contributors: Alice Smith (alice)</p>';
    $leaks = $this->makeService()->findNameLeaks($html, ['Alice Smith (alice)']);
    $this->assertContains('alice', $leaks);
  }

  public function testNameOnlyInContributorsLineIsNotFlagged(): void {
    $html = '<p>The provider refactor was merged.</p><p>Contributors: Alice Smith (alice), Bob (bob)</p>';
    $leaks = $this->makeService()->findNameLeaks($html, ['Alice Smith (alice)', 'Bob (bob)']);
    $this->assertSame([], $leaks);
  }

  public function testLeakDetectionIgnoresCommitCountSuffix(): void {
    // The contributor carries a "[2]" suffix; the name must still be detected
    // when it leaks into the prose, and the suffix must not be treated as part
    // of the name token.
    $html = '<p>Alice Smith pushed the fix.</p><p>Contributors: Alice Smith (alice) [2]</p>';
    $leaks = $this->makeService()->findNameLeaks($html, ['Alice Smith (alice) [2]']);
    $this->assertContains('Alice Smith', $leaks);
  }

  public function testContributorsLineWithCountIsNotFlagged(): void {
    $html = '<p>The provider refactor was merged.</p><p>Contributors: Alice Smith (alice) [3], Bob (bob)</p>';
    $leaks = $this->makeService()->findNameLeaks($html, ['Alice Smith (alice) [3]', 'Bob (bob)']);
    $this->assertSame([], $leaks);
  }

  public function testNoContributorsMeansNoLeaks(): void {
    $html = '<p>Alice Smith merged the fix.</p>';
    $this->assertSame([], $this->makeService()->findNameLeaks($html, []));
  }

  // ---------------------------------------------------------------------------
  // stripNameAttribution()
  // ---------------------------------------------------------------------------

  public function testCleanProseIsReturnedUnchangedWithoutLlmCall(): void {
    $s = $this->prophesize(AiSummariserService::class);
    $s->complete(\Prophecy\Argument::cetera())->shouldNotBeCalled();
    $service = $this->makeService($s->reveal());

    $html = '<p>The refactor was merged.</p><p>Contributors: Alice Smith (alice)</p>';
    $this->assertSame($html, $service->stripNameAttribution($html, ['Alice Smith (alice)'], 'ai'));
  }

  public function testLeakTriggersRewrite(): void {
    $rewritten = '<p>The fix was merged.</p><p>Contributors: Alice Smith (alice)</p>';
    $service = $this->makeService($this->summariserReturning($rewritten));

    $leaked = '<p>Alice Smith merged the fix.</p><p>Contributors: Alice Smith (alice)</p>';
    $result = $service->stripNameAttribution($leaked, ['Alice Smith (alice)'], 'ai');
    $this->assertSame($rewritten, $result);
  }

  public function testRewriteFailureFailsOpenToOriginal(): void {
    $s = $this->prophesize(AiSummariserService::class);
    $s->complete(\Prophecy\Argument::cetera())->willThrow(new \RuntimeException('boom'));
    $service = $this->makeService($s->reveal());

    $leaked = '<p>Alice Smith merged the fix.</p><p>Contributors: Alice Smith (alice)</p>';
    $result = $service->stripNameAttribution($leaked, ['Alice Smith (alice)'], 'ai');
    // Fail-open: original is returned rather than blocking the digest.
    $this->assertSame($leaked, $result);
  }

}
