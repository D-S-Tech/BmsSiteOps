<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	import { ApiError } from '$lib/api';
	import { registry } from '$lib/registry';
	import { formatTimestamp } from '$lib/format';
	import type { Question } from '$lib/types';

	let question = $state<Question | null>(null);
	let loading = $state(true);
	let error = $state<string | null>(null);

	const questionId = $derived(Number($page.params.id));

	onMount(async () => {
		try {
			const result = await registry.getQuestion(questionId);
			question = result.data;
		} catch (e) {
			error =
				e instanceof ApiError && e.status === 404
					? 'Question not found.'
					: e instanceof ApiError && e.status === 401
						? 'Sign in to view this question.'
						: e instanceof Error
							? e.message
							: 'Failed to load the question.';
		} finally {
			loading = false;
		}
	});

	function statusColor(status: Question['status']): string {
		switch (status) {
			case 'ready':
				return 'var(--color-success, #16a34a)';
			case 'failed':
				return 'var(--color-danger, #dc2626)';
			default:
				return 'var(--color-text-muted)';
		}
	}
</script>

<svelte:head>
	<title>{question?.question?.slice(0, 60) ?? 'Question'} · BmsSiteOps</title>
</svelte:head>

<div class="space-y-6">
	{#if loading}
		<p style="color: var(--color-text-muted);">Loading…</p>
	{:else if error || !question}
		<div
			class="rounded-lg border p-4 text-sm"
			style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
		>
			{error ?? 'Question not found.'}
		</div>
	{:else}
		<header>
			<a href="/qa" class="text-sm" style="color: var(--color-text-muted);">← Q&amp;A</a>
			<div class="mt-1 flex items-baseline justify-between gap-4">
				<h1 class="text-xl font-semibold tracking-tight">{question.question}</h1>
				<span style="color: {statusColor(question.status)}; font-weight: 500;">
					{question.status_label}
				</span>
			</div>
			<p class="mt-1 text-sm" style="color: var(--color-text-secondary);">
				Asked {formatTimestamp(question.asked_at)}
				{#if question.model}
					· {question.model}
				{/if}
				{#if question.answered_at}
					· answered {formatTimestamp(question.answered_at)}
				{/if}
			</p>
		</header>

		{#if question.status === 'failed'}
			<section
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-danger, #dc2626);"
			>
				<h2 class="mb-2 text-sm font-medium" style="color: var(--color-danger, #dc2626);">
					Pipeline failed
				</h2>
				<p class="text-sm whitespace-pre-line" style="color: var(--color-text-secondary);">
					{question.error}
				</p>
			</section>
		{/if}

		{#if question.answer}
			<section
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				<h2 class="mb-3 text-sm font-medium">Answer</h2>
				<div
					class="prose prose-sm max-w-none text-sm whitespace-pre-line"
					style="color: var(--color-text-primary);"
				>
					{question.answer}
				</div>
			</section>
		{/if}

		{#if question.citations.length > 0}
			<section class="space-y-3">
				<h2 class="text-sm font-medium">Citations</h2>
				<div class="space-y-2">
					{#each question.citations as c, i (c.chunk_id)}
						<div
							class="rounded-md border p-3 text-sm"
							style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
						>
							<div class="flex items-baseline justify-between gap-3">
								<span class="font-medium">
									[{i + 1}]
									{c.document_title ?? '(untitled document)'}
								</span>
								<span class="text-xs" style="color: var(--color-text-muted);">
									similarity {c.score.toFixed(3)}
								</span>
							</div>
							<a
								href="/documents/{c.document_id}"
								class="mt-1 inline-block text-xs hover:underline"
								style="color: var(--color-text-muted);"
							>
								document #{c.document_id} · chunk #{c.chunk_id}
							</a>
						</div>
					{/each}
				</div>
			</section>
		{/if}
	{/if}
</div>
