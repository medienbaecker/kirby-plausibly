<template>
	<k-dialog class="k-plausibly-license-dialog" size="large" :cancel-button="cancelButton"
		:submit-button="submitButton" :visible="true" @cancel="$emit('cancel')">
		<k-bar class="k-plausibly-license-header">
			<h2 class="k-headline">Kirby Plausibly</h2>
			<k-button :text="$t('remove')" icon="trash" size="xs" variant="filled" dialog="plausibly/remove-license" />
		</k-bar>

		<div class="k-table">
			<table class="k-plausibly-license-table">
				<tbody>
					<tr v-if="license.code">
						<th data-mobile="true">{{ $t("license.code") }}</th>
						<td data-mobile="true" class="k-text">
							<code>{{ license.code }}</code>
						</td>
					</tr>
					<tr v-if="license.version">
						<th data-mobile="true">{{ $t("version") }}</th>
						<td data-mobile="true">{{ license.version }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<p class="k-plausibly-license-thanks">
			{{ $t("medienbaecker.plausibly.license.active.info") }}
		</p>
	</k-dialog>
</template>

<script>
export default {
	props: {
		license: { type: Object, default: () => ({}) },
		cancelButton: { default: false },
		submitButton: { default: false },
	},
};
</script>

<style>
.k-plausibly-license-header {
	margin-block-end: var(--spacing-2);
}

.k-plausibly-license-table {
	table-layout: auto;
}

.k-plausibly-license-thanks {
	margin-block-start: var(--spacing-3);
	font-size: var(--text-xs);
	color: var(--color-text-dimmed);
}
</style>
