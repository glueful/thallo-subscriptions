# glueful/thallo-subscriptions

Workspace SaaS billing for [Thallo](https://thallo.dev): a platform-wide plan catalogue and a
subscription per workspace, as a capability pack over the `glueful/subscriptions` engine, with
checkout through `glueful/payvia`. The subscriber is always a workspace; on an install without
workspaces turned on, the site itself is the one workspace. Charging visitors for products is
`thallo-commerce`, not this pack.

## What it provides

- **Operator API** under `/v1/admin/subscriptions` (behind `auth` +
  `content_permission:tenancy.manage`): plan CRUD, archive and `plans/import-config`, the
  self-serve checkout kill switch (`PUT /self-serve`), and the workspace directory with plan
  assignment, cancellation and per-workspace entitlement overrides.
- **Workspace billing API** under `/v1/admin/billing` (behind `content_permission:billing.manage`
  in the current workspace): `GET /meta`, `POST /checkout`, `POST /cancel` and
  `POST /checkout/abandon`. A checkout goes to the gateway's hosted page; only the provider's
  webhook activates the subscription.
- **Admin screens** under the sidebar's **Subscriptions** group: **Plans**
  (`/subscriptions/plans`), **Billing** (`/subscriptions/billing`, the operator's self-serve
  switch and workspace directory) and **Workspace billing** (`/billing`, where a workspace
  subscribes, resumes or cancels).
- **Engine pre-emption**: `EnginePreemptionServiceProvider` boots before `glueful/subscriptions`,
  takes over the engine's own ungated `/subscriptions/plans*` routes and binds Thallo's
  workspace subject resolver.
- **Workspace purge** support, so deleting a workspace removes its billing data.
- **`php glueful subscriptions:checkout:resolve <uuid> --resolution=… --note=…`**: the operator's
  CLI-only way to close a stuck checkout.

Plan entitlements are for your own code to read; the one built-in effect is API rate limiting
through `rate.tier.{tier}` entitlements.

## Turning it on

The pack ships with Thallo: `glueful/thallo-core` requires it at the same version and the project's
`config/serviceproviders.php` loads its provider. It registers the `thallo.subscriptions`
capability, whose owning package is `glueful/subscriptions`. A new project enables that extension,
so the capability is **on by default**. An operator turns it off or on in the admin under
**Extensions › Capabilities** (stored system-wide; it overrides the deploy-time
`thallo.capabilities` config map). Self-serve checkout also needs `glueful/payvia` enabled
(`php glueful extensions:enable glueful/payvia`) and a gateway that supports subscription checkout,
configured in **Settings › Payments**.

## Documentation

The user guide is [`docs/guides/19-subscriptions.md`](../../docs/guides/19-subscriptions.md);
workspaces are explained in [`docs/concepts/08-workspaces.md`](../../docs/concepts/08-workspaces.md).

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-subscriptions/`.
