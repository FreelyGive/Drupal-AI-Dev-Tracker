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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Unit tests for the digest fact-checker on DailyDigestService.
 */
#[Group('issue_analysis')]
class DigestFactCheckerTest extends TestCase {

  use ProphecyTrait;

  /**
   * Builds a DailyDigestService with mocked dependencies.
   *
   * The logger is injected (not resolved via \Drupal::logger()), so the
   * fact-check fail-open logging paths exercise a real mock rather than the
   * static container.
   *
   * @param \Drupal\issue_analysis\Service\AiSummariserService|null $summariser
   *   Optional summariser mock; a dummy is used when NULL.
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

  public function testReferenceMapMarksMergedMrDone(): void {
    $results = [
      [
        'machine_name' => 'ai',
        'issues' => [],
        'merge_requests' => [
          ['web_url' => 'https://example/mr/1', 'title' => 'MR one', 'merged_at' => '2026-06-20T10:00:00Z', 'state' => 'merged'],
          ['web_url' => 'https://example/mr/2', 'title' => 'MR two', 'merged_at' => NULL, 'state' => 'opened'],
        ],
      ],
    ];
    $map = $this->makeService()->buildReferenceMap($results);

    $this->assertTrue($map[1]['done']);
    $this->assertSame('merged 2026-06-20', $map[1]['status_label']);
    $this->assertFalse($map[2]['done']);
    $this->assertSame('opened', $map[2]['status_label']);
  }

  public function testReferenceMapMarksClosedIssueDone(): void {
    $results = [
      [
        'machine_name' => 'ai',
        'issues' => [
          ['web_url' => 'https://example/i/1', 'title' => 'Issue one', 'state' => 'closed', 'closed_at' => '2026-06-19T08:00:00Z'],
          ['web_url' => 'https://example/i/2', 'title' => 'Issue two', 'state' => 'opened', 'closed_at' => NULL],
        ],
        'merge_requests' => [],
      ],
    ];
    $map = $this->makeService()->buildReferenceMap($results);

    $this->assertTrue($map[1]['done']);
    $this->assertSame('closed 2026-06-19', $map[1]['status_label']);
    $this->assertFalse($map[2]['done']);
    $this->assertSame('opened', $map[2]['status_label']);
  }

  /**
   * Reference map helper: one entry, given type and done state.
   */
  private function refMap(array $entries): array {
    $map = [];
    $n = 1;
    foreach ($entries as $e) {
      $map[$n] = [
        'n' => $n,
        'url' => "https://example/$n",
        'title' => $e['title'] ?? "Item $n",
        'type' => $e['type'] ?? 'mr',
        'module' => $e['module'] ?? 'ai',
        'done' => $e['done'],
        'status_label' => $e['done'] ? 'merged 2026-06-20' : 'opened',
      ];
      $n++;
    }
    return $map;
  }

  public function testOpenMrCitedAsMergedIsCorrected(): void {
    $map = $this->refMap([['done' => FALSE]]);
    $html = '<ol><li><strong>Context</strong> &mdash; UX improvements merged [1].</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    $this->assertStringContainsString('in progress [1]', $result['html']);
    $this->assertStringNotContainsString('merged [1]', $result['html']);
    $this->assertCount(1, $result['corrections']);
  }

  public function testMergedMrCitedAsMergedIsUntouched(): void {
    $map = $this->refMap([['done' => TRUE]]);
    $html = '<ol><li>UX improvements merged [1].</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    $this->assertSame($html, $result['html']);
    $this->assertCount(0, $result['corrections']);
  }

  public function testMixedCitationsAreUntouched(): void {
    $map = $this->refMap([['done' => FALSE], ['done' => TRUE]]);
    $html = '<ol><li>Work merged [1][2].</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    // At least one cited ref (2) is done, so the claim stands.
    $this->assertSame($html, $result['html']);
    $this->assertCount(0, $result['corrections']);
  }

  public function testOpenIssueCitedAsFixedIsCorrected(): void {
    $map = $this->refMap([['type' => 'issue', 'done' => FALSE]]);
    $html = '<ol><li>The crash was fixed [1].</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    $this->assertStringContainsString('being worked on [1]', $result['html']);
    $this->assertCount(1, $result['corrections']);
  }

  public function testUncitedClaimUntouchedByLayer1(): void {
    $map = $this->refMap([['done' => FALSE]]);
    $html = '<ol><li>We shipped the new UI.</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    $this->assertSame($html, $result['html']);
    $this->assertCount(0, $result['corrections']);
  }

  public function testUnknownCitationUntouched(): void {
    $map = $this->refMap([['done' => FALSE]]);
    $html = '<ol><li>Work merged [99].</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    $this->assertSame($html, $result['html']);
    $this->assertCount(0, $result['corrections']);
  }

  public function testLinkedCitationFormIsMatched(): void {
    $map = $this->refMap([['done' => FALSE]]);
    $html = '<ol><li>Work merged <a href="#ref-1" class="digest-citation">[1]</a>.</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    $this->assertStringContainsString('in progress', $result['html']);
    $this->assertCount(1, $result['corrections']);
    $this->assertStringNotContainsString('merged', $result['html']);
  }

  public function testAllCapsVerbReplacementKeepsCasing(): void {
    $map = $this->refMap([['done' => FALSE]]);
    $html = '<ol><li>FEATURE MERGED [1].</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    $this->assertStringContainsString('IN PROGRESS', $result['html']);
  }

  public static function completionVerbProvider(): array {
    return [
      'merged' => ['merged', 'in progress'],
      'shipped' => ['shipped', 'in progress'],
      'released' => ['released', 'in progress'],
      'landed' => ['landed', 'in progress'],
      'delivered' => ['delivered', 'in progress'],
      'completed' => ['completed', 'in progress'],
      'fixed' => ['fixed', 'being worked on'],
      'done' => ['done', 'in progress'],
    ];
  }

  #[DataProvider('completionVerbProvider')]
  public function testEachCompletionVerbIsSoftened(string $verb, string $replacement): void {
    $map = $this->refMap([['done' => FALSE]]);
    $html = "<ol><li>The work was $verb [1].</li></ol>";
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    $this->assertStringContainsString($replacement . ' [1]', $result['html']);
    $this->assertCount(1, $result['corrections']);
  }

  public function testVerbInsideTagNotRewritten(): void {
    $map = $this->refMap([['done' => FALSE]]);
    // 'fixed' appears in a class attribute AND in the prose.
    $html = '<ol><li><span class="fixed-width">Bug fixed [1]</span>.</li></ol>';
    $result = $this->makeService()->verifyCitedClaims($html, $map);

    // Attribute value untouched.
    $this->assertStringContainsString('class="fixed-width"', $result['html']);
    // Prose verb softened.
    $this->assertStringContainsString('being worked on [1]', $result['html']);
  }

  public function testModuleStatusTableGroupsByModule(): void {
    $results = [
      [
        'machine_name' => 'ai',
        'issues' => [['title' => 'Bug A', 'state' => 'closed', 'closed_at' => '2026-06-19T00:00:00Z', 'web_url' => 'https://example.com/issues/1']],
        'merge_requests' => [['title' => 'MR B', 'merged_at' => NULL, 'state' => 'opened', 'web_url' => 'x']],
      ],
    ];
    $table = $this->makeService()->buildModuleStatusTable($results);

    $this->assertStringContainsString('ai', $table);
    $this->assertStringContainsString('Bug A (Issue) — closed 2026-06-19', $table);
    $this->assertStringContainsString('MR B (MR) — opened', $table);
  }

  /**
   * Makes a summariser mock whose complete() returns the given string.
   */
  private function summariserReturning(string $response): AiSummariserService {
    $s = $this->prophesize(AiSummariserService::class);
    $s->complete(\Prophecy\Argument::cetera())->willReturn($response);
    return $s->reveal();
  }

  public function testJudgeParsesFlags(): void {
    $json = '[{"quote":"We shipped the new UI.","why":"Nothing merged in module ai.","cited":false}]';
    $service = $this->makeService($this->summariserReturning($json));
    $map = $this->refMap([['done' => FALSE]]);
    $flags = $service->judgeOverstatements('<ol><li>We shipped the new UI.</li></ol>', $map, []);

    $this->assertCount(1, $flags);
    $this->assertSame('We shipped the new UI.', $flags[0]['quote']);
    $this->assertFalse($flags[0]['cited']);
    $this->assertSame('Nothing merged in module ai.', $flags[0]['why']);
  }

  public function testJudgeMalformedJsonReturnsNoFlags(): void {
    $service = $this->makeService($this->summariserReturning('not json at all'));
    $map = $this->refMap([['done' => FALSE]]);
    $flags = $service->judgeOverstatements('<ol><li>x</li></ol>', $map, []);

    $this->assertSame([], $flags);
  }

  public function testJudgeProviderExceptionReturnsNoFlags(): void {
    $s = $this->prophesize(AiSummariserService::class);
    $s->complete(\Prophecy\Argument::cetera())->willThrow(new \RuntimeException('boom'));
    $service = $this->makeService($s->reveal());
    $map = $this->refMap([['done' => FALSE]]);
    $flags = $service->judgeOverstatements('<ol><li>x</li></ol>', $map, []);

    $this->assertSame([], $flags);
  }

  public function testJudgePromptContainsBothStatusTables(): void {
    $captured = '';
    $s = $this->prophesize(AiSummariserService::class);
    $s->complete(\Prophecy\Argument::any(), \Prophecy\Argument::any())
      ->will(function (array $args) use (&$captured) {
        $captured = $args[0];
        return '[]';
      });
    $service = $this->makeService($s->reveal());

    $results = [[
      'machine_name' => 'ai',
      'issues' => [],
      'merge_requests' => [['title' => 'MR B', 'merged_at' => NULL, 'state' => 'opened', 'web_url' => 'https://example/1']],
    ]];
    $map = $service->buildReferenceMap($results);

    $service->judgeOverstatements('<ol><li>Work merged [1].</li></ol>', $map, $results);

    // Cited-reference table (from buildReferenceIndex) and module status table.
    $this->assertStringContainsString('[1]', $captured);
    $this->assertStringContainsString('### ai', $captured);
    $this->assertStringContainsString('MR B', $captured);
  }

  public function testJudgeSkipsMalformedItems(): void {
    // Mix of: missing-quote object, a scalar, and one valid flag.
    $json = '[{"why":"no quote here","cited":true},42,{"quote":"Real claim","why":"open MR","cited":true}]';
    $service = $this->makeService($this->summariserReturning($json));
    $map = $this->refMap([['done' => FALSE]]);
    $flags = $service->judgeOverstatements('<ol><li>x</li></ol>', $map, []);

    $this->assertCount(1, $flags);
    $this->assertSame('Real claim', $flags[0]['quote']);
  }

  public function testRewriteNoFlagsReturnsInputUnchanged(): void {
    $s = $this->prophesize(AiSummariserService::class);
    $s->complete(\Prophecy\Argument::cetera())->shouldNotBeCalled();
    $service = $this->makeService($s->reveal());
    $map = $this->refMap([['done' => FALSE]]);
    $html = '<ol><li>Work merged [1].</li></ol>';
    // verifyCitedClaims still runs as backstop, so "merged [1]" on an open MR
    // gets softened even with zero judge flags.
    $out = $service->rewriteFlagged($html, [], $map, []);

    $this->assertStringContainsString('in progress', $out);
  }

  public function testRewriteAppliesLlmOutputThenBackstops(): void {
    // LLM rewrite "fixes" the prose but wrongly still says "merged [1]";
    // the layer-1 backstop must correct it because ref 1 is an open MR.
    $service = $this->makeService($this->summariserReturning('<ol><li>Work merged [1].</li></ol>'));
    $map = $this->refMap([['done' => FALSE]]);
    $flags = [['quote' => 'Work is live.', 'why' => 'open', 'cited' => TRUE]];
    $out = $service->rewriteFlagged('<ol><li>Work is live [1].</li></ol>', $flags, $map, []);

    $this->assertStringContainsString('in progress', $out);
    $this->assertStringNotContainsString('merged [1]', $out);
  }

  public function testRewriteCallFailureFallsBackToInput(): void {
    $s = $this->prophesize(AiSummariserService::class);
    $s->complete(\Prophecy\Argument::cetera())->willThrow(new \RuntimeException('boom'));
    $service = $this->makeService($s->reveal());
    $map = $this->refMap([['done' => TRUE]]);
    $flags = [['quote' => 'x', 'why' => 'y', 'cited' => FALSE]];
    $html = '<ol><li>All good [1].</li></ol>';
    $out = $service->rewriteFlagged($html, $flags, $map, []);

    // Falls back to the layer-1-checked input (ref done, so unchanged).
    $this->assertSame($html, $out);
  }

  public function testGenerateTldrAppliesFactCheck(): void {
    // Summariser is called twice: once for the TL;DR text, once for the judge.
    // First call returns a TL;DR that falsely says "merged [1]"; the judge
    // (second call) returns [] so only layer 1 corrects it.
    $s = $this->prophesize(AiSummariserService::class);
    $calls = 0;
    $tldrHtml = '<h4>Shipped</h4><ol><li>Context UI merged [1].</li></ol>';
    $s->complete(\Prophecy\Argument::cetera())->will(function () use (&$calls, $tldrHtml) {
      $calls++;
      return $calls === 1 ? $tldrHtml : '[]';
    });
    $service = $this->makeService($s->reveal());

    $results = [[
      'machine_name' => 'ai',
      'issues' => [],
      'merge_requests' => [['title' => 'Context UI', 'merged_at' => NULL, 'state' => 'opened', 'web_url' => 'https://example/1']],
    ]];

    $out = $service->generateTldr(['ai' => 'x'], '24h', 'html', 'developer', $results);

    $this->assertStringContainsString('in progress', $out);
    $this->assertStringNotContainsString('merged [1]', $out);
  }

}
