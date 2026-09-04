<?php

declare(strict_types=1);

use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * D12's premise (.claude/plans/archive/package-extraction.md, "Decisions taken"):
 * every one of the 9 config('numerosis.models') classes is resolved through
 * Numerosis::model() at its call site, never referenced literally, so a
 * host's config override actually reaches every call site instead of just
 * the ~9 framework-integration ones. Nothing enforced this — models stopped
 * being abstract under D8, so a bypass no longer crashes with
 * "Cannot instantiate abstract class", it just silently ignores the host's
 * override, which is quieter than the bug it replaced. This is step 3 of
 * the plan.
 *
 * Scans every file under src/ (excluding the models themselves and the
 * resolver) with nikic/php-parser for StaticCall / StaticPropertyFetch /
 * New nodes whose class name resolves, via NameResolver, to one of the 9
 * watched FQCNs. `Tenant::class` is a ClassConstFetch, not a StaticCall —
 * used as Numerosis::model()'s argument, a relation definition, a factory
 * declaration, or a docblock, it is never flagged. `self::`/`static::`
 * inside a model's own class body resolve to the literal names "self"/
 * "static", never to the FQCN, so a model calling its own statics would
 * not be flagged even if the exclusion below were removed.
 */
test('every package call site resolves the 9 config-overridable models through Numerosis::model()', function (): void {
    $watched = [
        Tenant::class,
        Domain::class,
        CentralUser::class,
        Subscription::class,
        PaymentPlan::class,
        PendingTenantProvision::class,
        Invitation::class,
        SocialAccount::class,
        TenantUser::class,
    ];

    $srcRoot = dirname(__DIR__, 3).'/src';

    // The resolver itself only reads config and returns a class-string — it
    // never constructs or calls a static method on the models it resolves.
    $exemptFiles = [
        $srcRoot.'/Support/Numerosis.php',
    ];

    // A model referencing itself is definitionally not a bypass; excluded
    // wholesale rather than relying on self::/static:: never matching, so
    // e.g. a static factory method calling `new self()` is not flagged.
    $exemptDirectory = $srcRoot.'/Models/';

    $files = (new Finder)->files()->in($srcRoot)->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — src/ path is wrong.');

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $violations = [];

    foreach ($files as $file) {
        $path = $file->getRealPath();
        if ($path === false) {
            continue;
        }
        if (in_array($path, $exemptFiles, true)) {
            continue;
        }
        if (str_starts_with($path, $exemptDirectory)) {
            continue;
        }

        $ast = $parser->parse($file->getContents());

        if ($ast === null) {
            continue;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        $visitor = new class($watched) extends NodeVisitorAbstract
        {
            /** @var list<string> */
            public array $found = [];

            /** @param list<class-string> $watched */
            public function __construct(private readonly array $watched) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof StaticCall
                    && ! $node instanceof StaticPropertyFetch
                    && ! $node instanceof New_) {
                    return null;
                }

                $class = $node->class;

                if (! $class instanceof Name) {
                    return null;
                }

                $resolved = $class->toString();

                if (in_array($resolved, $this->watched, true)) {
                    $this->found[] = $resolved.' at line '.$node->getStartLine();
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if ($visitor->found !== []) {
            $violations[$file->getRelativePathname()] = $visitor->found;
        }
    }

    expect($violations)->toBe(
        [],
        "Bare static call, static property access, or `new` on a config-overridable model — bypasses Numerosis::model(), so a host's config override never reaches this call site:\n"
        .print_r($violations, true)
    );
});
