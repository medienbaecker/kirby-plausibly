<template>
	<k-section class="k-plausible-card">
		<template #options>
			<div class="k-plausible-card-header">
				<k-label type="section" :title="title">{{ title }}</k-label>
				<nav v-if="tabs.length > 1" class="k-plausible-tabs">
					<button v-for="t in tabs" :key="t.name" type="button" class="k-plausible-tab"
						:aria-current="t.name === active ? 'true' : null" @click="select(t.name)">
						{{ t.label }}
					</button>
				</nav>
			</div>
		</template>

		<k-empty v-if="loading && !rows.length" icon="loader" />
		<div v-else-if="selection" class="k-plausible-selection" :aria-busy="loading || null">
			<img v-if="loadedTab.icon === 'favicon'" class="k-plausible-icon"
				:src="faviconBase + encodeURIComponent(selection.raw)" alt="" loading="lazy"
				@error="$event.target.style.visibility = 'hidden'" />
			<span v-else-if="loadedTab.icon === 'flag'" class="k-plausible-flag">{{ countryFlag(selection.raw)
			}}</span>
			<span class="k-plausible-text" :title="selection.label">{{ selection.label }}</span>
			<k-button v-if="selection.page" :link="selection.page.link" :title="selection.page.title" icon="open"
				size="xs" target="_blank" class="k-plausible-page-link" />
			<k-button class="k-plausible-selection-remove" icon="cancel" size="xs"
				:title="$t('medienbaecker.plausibly.filter.reset')"
				:aria-label="$t('medienbaecker.plausibly.filter.reset')" @click="clearSelection" />
		</div>
		<k-empty v-else-if="!rows.length" icon="chart">
			{{ $t("medienbaecker.plausibly.empty") }}
		</k-empty>
		<div v-else class="k-plausible-rows" :aria-busy="loading || null">
			<k-table :class="['k-plausible-bars', { 'k-plausible-flush': loadedTab.columns.length === 1 }]"
				:columns="table.columns" :rows="visibleRows" :index="false" @cell="onCell" />
		</div>
	</k-section>
</template>

<script>
import { toTable, fetchData, decodeFilters, countryFlag } from "../helpers.js";

const COLLAPSED = 9;
const MAX = 100;

export default {
	props: {
		title: { type: String, required: true },
		tabs: { type: Array, required: true },
		period: { type: String, default: "28d" },
		date: { type: String, default: null },
		from: { type: String, default: null },
		to: { type: String, default: null },
		filters: { type: String, default: "" },
		faviconBase: { type: String, default: "" },
		defaultTab: { type: String, default: null },
	},
	data() {
		return {
			active: this.defaultTab || this.tabs[0].name,
			loaded: this.defaultTab || this.tabs[0].name,
			loadedFilters: this.filters,
			rows: [],
			loading: false,
			expanded: false,
		};
	},
	computed: {
		tab() {
			return this.tabs.find((t) => t.name === this.active) || this.tabs[0];
		},
		// the one selected value when the displayed tab's dimension is filtered;
		// replaces the degenerate one-row table
		selection() {
			if (this.activeFilterValue === null) return null;
			const row = this.table.rows.find((r) => r._raw === this.activeFilterValue);
			return {
				raw: this.activeFilterValue,
				label: row ? row.label : this.activeFilterValue,
				page: row ? row._page : null,
			};
		},
		loadedTab() {
			return this.tabs.find((t) => t.name === this.loaded) || this.tabs[0];
		},
		// value of the "is" filter the displayed rows were loaded with, so the
		// remove affordance never shows on stale rows while new ones load
		activeFilterValue() {
			const filter = decodeFilters(this.loadedFilters).find(
				(f) => f.dimension === this.loadedTab.dimension && f.op === "is" && f.values.length === 1
			);
			return filter ? filter.values[0] : null;
		},
		table() {
			// Built from the loaded tab, never the selected one, so columns and
			// label formatting always match the rows currently in hand.
			const table = toTable(this.rows, {
				columnDefs: this.loadedTab.columns,
				labelHeader: this.loadedTab.labelHeader,
				iconType: this.loadedTab.icon,
				faviconBase: this.faviconBase,
				notSet: this.$t("medienbaecker.plausibly.notSet"),
				locale: this.locale,
			});
			if (this.activeFilterValue !== null) {
				for (const row of table.rows) {
					row._filtered = row._raw === this.activeFilterValue;
				}
			}
			return table;
		},
		visibleRows() {
			const all = this.table.rows;
			const out = this.expanded ? [...all] : all.slice(0, COLLAPSED);
			if (all.length > COLLAPSED) {
				out.push({
					_more: true,
					_expanded: this.expanded,
					label: this.$t(
						"medienbaecker.plausibly." + (this.expanded ? "showLess" : "showMore")
					),
				});
			}
			return out;
		},
		locale() {
			return this.$panel.translation?.code || "en";
		},
		// One key for all so changing period+date+range+filters together reloads once.
		window() {
			return `${this.period}|${this.date}|${this.from}|${this.to}|${this.filters}`;
		},
	},
	watch: {
		window() {
			this.expanded = false;
			this.load();
		},
	},
	mounted() {
		this.load();
	},
	methods: {
		async load() {
			const token = (this.loadToken = Symbol());
			this.loading = true;
			try {
				const rows = await fetchData(this.$api, "plausible/breakdown", {
					dimension: this.tab.dimension,
					metrics: this.tab.metrics.join(","),
					period: this.period,
					...(this.date ? { date: this.date } : {}),
					...(this.from && this.to ? { from: this.from, to: this.to } : {}),
					// $api.get() does not encode query values, PHP decodes once
					...(this.filters ? { filters: encodeURIComponent(this.filters) } : {}),
					limit: MAX,
				});
				if (token !== this.loadToken) return;
				// Swap rows and the displayed tab together so the table never
				// renders new columns against the previous tab's data.
				this.rows = rows;
				this.loaded = this.active;
				this.loadedFilters = this.filters;
			} catch (e) {
				if (token !== this.loadToken) return;
				this.$panel.notification.error(e.message || this.$t("medienbaecker.plausibly.error"));
				this.rows = [];
				this.loaded = this.active;
				this.loadedFilters = this.filters;
			} finally {
				if (token === this.loadToken) this.loading = false;
			}
		},
		select(name) {
			if (name === this.active) return;
			this.active = name;
			this.expanded = false;
			this.load();
		},
		countryFlag,
		clearSelection() {
			this.$emit("filter", {
				op: "is",
				dimension: this.loadedTab.dimension,
				values: [this.activeFilterValue],
			});
		},
		onCell({ row }) {
			if (row && row._more) {
				this.expanded = !this.expanded;
				return;
			}
			if (!row || row._raw === "") return; // "(not set)" is not filterable
			this.$emit("filter", {
				op: "is",
				dimension: this.loadedTab.dimension,
				values: [row._raw],
			});
		},
	},
};
</script>

<style>
/* the section renders an empty built-in label; ours lives in the options slot */
.k-plausible-card .k-section-header > .k-section-label {
	display: none;
}

.k-plausible-card-header {
	flex-grow: 1;
	display: flex;
	align-items: center;
	gap: var(--spacing-3);
	min-width: 0;
}

.k-plausible-card-header > .k-label {
	flex: 0 0 auto;
}

.k-plausible-selection {
	display: flex;
	align-items: center;
	gap: var(--spacing-2);
	height: calc(var(--table-row-height) + 2px);
	padding-inline: var(--table-cell-padding);
	background: var(--table-color-back);
	border: 1px solid var(--table-color-border);
	border-radius: var(--rounded);
}

.k-plausible-selection-remove {
	margin-inline-start: auto;
	height: 1.125rem;
	padding-inline: var(--spacing-1);
}

.k-plausible-tabs {
	display: flex;
	align-items: center;
	gap: var(--spacing-3);
	margin-inline-start: auto;
}

.k-plausible-tab {
	font-size: var(--text-xs);
	color: var(--color-text-dimmed);
	cursor: pointer;
	border-radius: var(--rounded-sm);

	&:is(:hover, :focus-visible) {
		color: var(--color-text);
	}

	&[aria-current="true"] {
		color: var(--color-text);
		font-weight: var(--font-semi);
	}
}
</style>
