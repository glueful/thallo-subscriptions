<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Payvia\Checkout\CheckoutReconciliationRefused;
use Glueful\Extensions\Subscriptions\Subject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Subscriptions\Checkout\CheckoutReservationRelease;
use Thallo\Subscriptions\Checkout\PayviaCheckoutGateway;
use Thallo\Subscriptions\Checkout\ReservationSettledException;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Engine\EngineUnavailableException;

/**
 * Task 17 (design spec §3.8/§5.2's `subscriptions:checkout:resolve` bullet): the ONLY sanctioned
 * way to move a stuck `pending`, `projection_rejected`, or `late_settlement_conflict` checkout
 * origination forward. Console = platform operator authority by definition (mirrors every
 * `thallo:tenancy:*`/`thallo:commerce:*` maintenance command in this codebase -- there is no
 * separate "is this an operator" check inside `execute()`, because reaching a server console at
 * all already proves that authority; see e.g. {@see \Thallo\Tenancy\Console\TenancyDisableCommand}
 * for the same unguarded-by-design precedent). Deliberately NOT mounted through any HTTP route --
 * see `routes/billing-routes.php`'s own docblock.
 *
 * No constructor override (mirrors {@see \Thallo\Commerce\Console\ReconcileLinksCommand}): every
 * dependency is resolved lazily inside {@see self::execute()} via {@see BaseCommand::getService()},
 * so this pack's `discoverCommands()` registration never risks crashing ANY `php glueful ...`
 * invocation just because `glueful/payvia` or `glueful/subscriptions` happen to be inactive in a
 * given host -- an inactive extension is reported as a plain command failure, never a fatal error
 * at container-build time.
 *
 * **The exact ordering this command relies on** (design spec §3.8/§4.1, same ledger note as
 * {@see \Thallo\Subscriptions\Http\SelfBillingController::abandon()}): `CheckoutReconciliationService
 * ::resolve()` invokes the continuation passed below INSIDE its own single owning transaction,
 * AFTER the origination's status/audit-note write and the subject guard's CAS reopen have already
 * been staged (not yet committed). The continuation calls
 * {@see CheckoutReservationRelease::releaseOrDetectSettled()}; if that reports the bound
 * reservation is SETTLED (provider fields already present -- the checkout actually completed),
 * the continuation THROWS {@see ReservationSettledException} rather than returning quietly. That
 * throw propagates out of `resolve()`'s try block, which rolls the origination/guard writes back
 * together -- an operator can never mistakenly reconcile-away a checkout that in fact succeeded.
 * A `false` return with NO settled reservation (nothing left to release) and a `true` return
 * (genuinely released) both fall through as success, letting `resolve()` commit normally.
 *
 * Prints NO provider payload/PII: only the origination uuid, the requested resolution, and (on
 * failure) the SANITIZED `CheckoutReconciliationRefused`/engine-unavailable message -- never the
 * origination row itself (which may carry `customer_email`, checkout URLs, or raw provider ids).
 */
#[AsCommand(
    name: 'subscriptions:checkout:resolve',
    description: 'Operator reconciliation for a stuck/rejected checkout origination (design spec §3.8).',
)]
final class CheckoutResolveCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('origination', InputArgument::REQUIRED, 'The checkout origination uuid to resolve.');
        $this->addOption(
            'resolution',
            null,
            InputOption::VALUE_REQUIRED,
            'provider_confirmed_dead|provider_canceled_or_refunded',
        );
        $this->addOption('note', null, InputOption::VALUE_REQUIRED, 'Non-empty audit note/reference.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $originationUuid = trim((string) $input->getArgument('origination'));
        $resolution = trim((string) $input->getOption('resolution'));
        $note = trim((string) $input->getOption('note'));

        if ($note === '') {
            $this->error('--note is required and must be a non-empty audit note/reference.');

            return self::FAILURE;
        }

        $context = $this->getContext();
        $payvia = new PayviaCheckoutGateway($context);
        if (!$payvia->isAvailable()) {
            $this->error('The billing provider checkout ledger is unavailable on this platform.');

            return self::FAILURE;
        }

        $origination = $payvia->originations()->findByUuid($originationUuid);
        if ($origination === null) {
            $this->error(sprintf('No checkout origination found for uuid "%s".', $originationUuid));

            return self::FAILURE;
        }

        // NOT `$origination['tenant_uuid']` -- that column is PAYVIA's OWN ledger tenant scope
        // (the sentinel `''` on a single-store host), a completely different value from the
        // WORKSPACE tenant uuid `reserveCheckoutFor()`/`releaseCheckoutReservation()` key their
        // `Subject` by. The workspace uuid is recorded LOCAL-ONLY in `consumer_metadata.tenant_uuid`
        // (design spec §3.4's enrichment fields; set verbatim by
        // `WorkspaceCheckoutCoordinator::prepare()`'s own `consumerMetadata`) -- reading the ledger
        // scope column here instead would silently resolve/release the wrong tenant's reservation.
        $metadata = is_array($origination['consumer_metadata'] ?? null) ? $origination['consumer_metadata'] : [];
        $subjectTenantUuid = is_string($metadata['tenant_uuid'] ?? null) ? trim($metadata['tenant_uuid']) : '';
        if ($subjectTenantUuid === '') {
            $this->error(sprintf(
                'Checkout origination %s has no recorded subject metadata; cannot safely resolve its bound '
                    . 'reservation.',
                $originationUuid,
            ));

            return self::FAILURE;
        }
        $subject = Subject::tenant($subjectTenantUuid);

        $engineGateway = $this->getService(EngineGateway::class);
        try {
            $engine = $engineGateway->requireServices();
        } catch (EngineUnavailableException $e) {
            $this->error(sprintf(
                'The subscriptions engine is unavailable (%s); cannot safely release any reservation.',
                $e->state,
            ));

            return self::FAILURE;
        }

        try {
            $payvia->reconciliation()->resolve(
                $context,
                $originationUuid,
                $resolution,
                $note,
                function (string $resolvedOriginationUuid) use ($engine, $subject): void {
                    $settled = CheckoutReservationRelease::releaseOrDetectSettled(
                        $engine->subscriptions(),
                        $subject,
                        $resolvedOriginationUuid,
                    );
                    if ($settled) {
                        throw new ReservationSettledException($resolvedOriginationUuid);
                    }
                },
            );
        } catch (CheckoutReconciliationRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ReservationSettledException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->success(sprintf(
            'Checkout origination %s resolved as "%s".',
            $originationUuid,
            $resolution,
        ));

        return self::SUCCESS;
    }
}
