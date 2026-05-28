<script lang="ts">
	import '../app.css';

	let { children } = $props();
</script>

<div class="flex min-h-dvh flex-col">
	<header
		class="border-b"
		style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
	>
		<div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-3">
			<a href="/" class="flex items-center gap-2 font-semibold tracking-tight">
				<span class="inline-block h-2 w-2 rounded-full" style="background: var(--color-bmce-red);"
				></span>
				BmsSiteOps
			</a>
			<nav class="flex items-center gap-6 text-sm" style="color: var(--color-text-secondary);">
				<a href="/sites">Sites</a>
				<a href="/alerts">Alerts</a>
				<a href="/scripts">Scripts</a>
				<a href="/qa">Q&amp;A</a>
			</nav>
		</div>
	</header>

	<main class="mx-auto w-full max-w-7xl flex-1 px-6 py-8">
		{@render children()}
	</main>

	<footer
		class="border-t text-xs"
		style="color: var(--color-text-muted); border-color: var(--color-border-subtle);"
	>
		<div class="mx-auto max-w-7xl px-6 py-3">
			BmsSiteOps · multi-tenant operations platform for HVAC/MEP contractors
		</div>
	</footer>
</div>
