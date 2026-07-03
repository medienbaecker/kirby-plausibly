<template>
	<button v-if="row._more" type="button" class="k-plausible-more" :aria-expanded="row._expanded ? 'true' : 'false'">
		{{ value }}
		<k-icon :type="row._expanded ? 'angle-up' : 'angle-down'" />
	</button>
	<span v-else class="k-plausible-barlabel">
		<span class="k-plausible-bar" :style="{ width: row._bar }" aria-hidden="true" />
		<img v-if="column.iconType === 'favicon'" class="k-plausible-icon"
			:src="column.faviconBase + encodeURIComponent(row._raw)" alt="" loading="lazy"
			@error="$event.target.style.visibility = 'hidden'" />
		<span v-else-if="column.iconType === 'flag'" class="k-plausible-flag">{{ flag }}</span>
		<button v-if="row._raw !== ''" type="button" class="k-plausible-text k-plausible-filter-label"
			:aria-pressed="row._filtered ? 'true' : null"
			:aria-label="$t('medienbaecker.plausibly.' + (row._filtered ? 'filterBy.remove' : 'filterBy'), { value })"
			:title="value">{{ value }}</button>
		<span v-else class="k-plausible-text" :title="value">{{ value }}</span>
		<k-button v-if="row._page" :link="row._page.link" :title="row._page.title" icon="open" size="xs"
			target="_blank" class="k-plausible-page-link" @click.native.stop />
	</span>
</template>

<script>
import { countryFlag } from "../helpers.js";

export default {
	props: {
		column: { type: Object, default: () => ({}) },
		field: { type: Object, default: () => ({}) },
		row: { type: Object, default: () => ({}) },
		value: { default: "" },
	},
	computed: {
		flag() {
			return countryFlag(this.row._raw);
		},
	},
};
</script>

<style>
.k-plausible-bars table {
	table-layout: auto;
	width: 100%;
}

.k-plausible-bars :is(th, td):not([data-column-id="label"]) {
	width: 1px !important;
	white-space: nowrap;
}

.k-plausible-bars td[data-column-id="label"] {
	width: 100% !important;
}

.k-plausible-flush :is(th, td) {
	border-inline-end: 0;
}

.k-plausible-bars td[data-column-id="percentage"] {
	color: var(--color-text-dimmed);
}

.k-plausible-bars tbody tr {
	position: relative;
	z-index: 0;
	background: var(--table-color-back);
	cursor: pointer;
}

.k-plausible-more {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: var(--spacing-1);
	color: var(--color-text-dimmed);
	font-size: var(--text-sm);
	white-space: nowrap;
	cursor: pointer;
	background-color: var(--table-color-back);
	--focus-outline-offset: -0.25rem;
	border-radius: var(--rounded);

	&:is(:hover, :focus-visible) {
		color: var(--color-text);
	}
}

.k-plausible-bars tbody tr:has(.k-plausible-more) td {
	height: var(--table-row-height);
}

.k-plausible-barlabel {
	display: flex;
	align-items: center;
	gap: var(--spacing-2);
	min-width: 0;
	padding: var(--table-cell-padding);
}

.k-plausible-bar {
	position: absolute;
	inset-block: var(--spacing-1);
	inset-inline-start: var(--spacing-1);
	max-width: calc(100% - var(--spacing-2));
	background: var(--plausible-fill);
	border-radius: var(--rounded);
	pointer-events: none;
}

.k-plausible-icon {
	width: 1rem;
	height: 1rem;
	flex: 0 0 1rem;
	object-fit: contain;
	z-index: 1;
}

.k-plausible-flag {
	flex: 0 0 auto;
	line-height: 1;
	z-index: 1;
}

.k-plausible-text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-inline-size: 28ch;
	z-index: 1;
}

.k-plausible-filter-label {
	cursor: pointer;
	text-align: start;
	border-radius: var(--rounded-sm);
}

/* the whole row is the click target, so hovering it anywhere signals the action */
.k-plausible-bars tbody tr:hover .k-plausible-filter-label,
.k-plausible-filter-label:focus-visible {
	text-decoration: underline;
}

/* clicking the actively filtered value removes the filter */
.k-plausible-bars tbody tr:hover .k-plausible-filter-label[aria-pressed],
.k-plausible-filter-label[aria-pressed]:focus-visible {
	text-decoration: line-through;
}

.k-plausible-page-link {
	flex: 0 0 auto;
	z-index: 1;
	opacity: 0.5;
	height: 1.125rem;
	padding-inline: var(--spacing-1);

	&:is(:hover, :focus-visible) {
		opacity: 1;
	}
}
</style>
