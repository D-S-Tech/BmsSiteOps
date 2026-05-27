<script lang="ts">
	// Sprint 0 Day 4 — landing stub. Sprint 3 will replace this with the real
	// site dashboard (per-site cards, recent alerts, AI-generated site brief).
</script>

<div class="space-y-8">
	<section class="space-y-3">
		<h1 class="text-3xl font-semibold tracking-tight">BmsSiteOps</h1>
		<p class="max-w-2xl" style="color: var(--color-text-secondary);">
			Operations platform unifying IT endpoint monitoring (Tactical RMM) with building automation
			telemetry (Tridium Niagara, BACnet/IP) under one site model, with an AI layer for triage,
			reporting, and script authoring.
		</p>
	</section>

	<section class="grid grid-cols-1 gap-4 md:grid-cols-3" aria-label="Sprint 0 capability stubs">
		<article
			class="rounded-lg border p-4"
			style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
		>
			<h2 class="mb-1 font-medium">Sites</h2>
			<p class="text-sm" style="color: var(--color-text-secondary);">
				The unit of operation. Each site aggregates devices, alerts, and AI briefings.
			</p>
		</article>

		<article
			class="rounded-lg border p-4"
			style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
		>
			<h2 class="mb-1 font-medium">Alerts</h2>
			<p class="text-sm" style="color: var(--color-text-secondary);">
				Triaged from TRMM, Niagara, and BACnet sources. AI proposes the first response.
			</p>
		</article>

		<article
			class="rounded-lg border p-4"
			style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
		>
			<h2 class="mb-1 font-medium">Scripts</h2>
			<p class="text-sm" style="color: var(--color-text-secondary);">
				AI-authored remediation scripts, reviewable before execution against the field.
			</p>
		</article>
	</section>

	<section
		class="rounded-lg border p-4 text-sm"
		style="background: var(--color-surface-2); border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
	>
		<strong style="color: var(--color-text-primary);">Sprint 0 Day 4</strong> — UI scaffold, typed API
		client, design tokens. Real auth, sites, and dashboards land in Sprint 1.
	</section>
</div>
