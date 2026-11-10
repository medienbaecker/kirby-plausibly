<template>
	<k-panel-inside class="k-plausible-view">
		<k-header>
			{{ site }}

			<template #buttons>
				<span v-if="realtime !== null" class="k-plausible-live" :data-active="realtime > 0">
					<k-icon type="live" class="k-plausible-live-icon" />
					{{ realtimeLabel }}
				</span>

				<div class="k-plausible-period">
					<k-button :dropdown="true" icon="calendar" variant="filled" size="sm" :text="periodLabel"
						@click="$refs.periods.toggle()" />
					<k-dropdown ref="periods" align-x="end">
						<template v-for="(item, i) in menuItems" :key="item.key ?? 'hr' + i">
							<hr v-if="item.divider" />
							<k-dropdown-item v-else icon="calendar" :current="isCurrent(item)"
								@click="selectItem(item)">
								{{ itemLabel(item) }}
								<kbd v-if="item.hotkey" class="k-plausible-hotkey">{{ item.hotkey }}</kbd>
							</k-dropdown-item>
						</template>
					</k-dropdown>
				</div>

				<div v-if="comparison" class="k-plausible-compare">
					<k-button :dropdown="true" variant="filled" size="sm" :text="compareLabel"
						@click="$refs.compare.toggle()" />
					<k-dropdown ref="compare" align-x="end">
						<k-dropdown-item :current="comparison === 'previous_period'"
							@click="setComparison('previous_period')">
							{{ compareModes.previous_period }}
						</k-dropdown-item>
						<k-dropdown-item :current="comparison === 'year_over_year'"
							@click="setComparison('year_over_year')">
							{{ compareModes.year_over_year }}
						</k-dropdown-item>
						<k-dropdown-item :current="comparison === 'custom'" @click="openCompareRangeDialog">
							{{ compareModes.custom }}
						</k-dropdown-item>
						<hr />
						<k-dropdown-item :icon="matchDow ? 'check' : 'blank'" @click="toggleMatchDow">
							{{ $t("medienbaecker.plausibly.compare.matchDayOfWeek") }}
						</k-dropdown-item>
						<hr />
						<k-dropdown-item icon="cancel" @click="setComparison(null)">
							{{ $t("medienbaecker.plausibly.compare.disable") }}
						</k-dropdown-item>
					</k-dropdown>
				</div>

				<k-button-group layout="collapsed" class="k-plausible-nav">
					<k-button icon="angle-left" variant="filled" size="sm" :disabled="prevDisabled"
						:title="$t('medienbaecker.plausibly.previousPeriod')" @click="shiftPeriod(-1)" />
					<k-button icon="angle-right" variant="filled" size="sm" :disabled="nextDisabled"
						:title="$t('medienbaecker.plausibly.nextPeriod')" @click="shiftPeriod(1)" />
				</k-button-group>
			</template>
		</k-header>

		<StatTiles :data="kpis" :selected="metric" @select="setMetric" />

		<section class="k-plausible-chart-card">
			<Chart :series="series" :comparison="comparisonSeries" :metric="metric" :interval="interval"
				:loading="loadingChart" @select="onChartSelect" />
		</section>

		<k-grid variant="columns" class="k-plausible-grid">
			<k-column width="1/2">
				<BreakdownCard :title="$t('medienbaecker.plausibly.topSources')" :tabs="cards.sources"
					default-tab="sources" :period="period" :date="date" :from="from" :to="to" :filters="filterQuery"
					:favicon-base="faviconBase" @filter="toggleFilter" />
			</k-column>
			<k-column width="1/2">
				<BreakdownCard :title="$t('medienbaecker.plausibly.topPages')" :tabs="cards.pages" :period="period"
					:date="date" :from="from" :to="to" :filters="filterQuery" @filter="toggleFilter" />
			</k-column>
			<k-column width="1/2">
				<BreakdownCard :title="$t('medienbaecker.plausibly.locations')" :tabs="cards.locations"
					:period="period" :date="date" :from="from" :to="to" :filters="filterQuery" @filter="toggleFilter" />
			</k-column>
			<k-column width="1/2">
				<BreakdownCard :title="$t('medienbaecker.plausibly.devices')" :tabs="cards.devices"
					:period="period" :date="date" :from="from" :to="to" :filters="filterQuery" @filter="toggleFilter" />
			</k-column>
			<k-column width="1/1">
				<BreakdownCard :title="$t('medienbaecker.plausibly.goals')" :tabs="cards.goals" :period="period"
					:date="date" :from="from" :to="to" :filters="filterQuery" @filter="toggleFilter" />
			</k-column>
		</k-grid>
	</k-panel-inside>
</template>

<script>
import StatTiles from "./StatTiles.vue";
import Chart from "./Chart.vue";
import BreakdownCard from "./BreakdownCard.vue";
import {
	PERIODS,
	intervalFor,
	fetchData,
	STEP,
	shiftAnchor,
	navLabel,
	rangeLabel,
	spanDays,
	yesterdayYmd,
	encodeFilters,
	decodeFilters,
} from "../helpers.js";

export default {
	components: { StatTiles, Chart, BreakdownCard },
	props: {
		site: { type: String, default: "" },
		faviconBase: { type: String, default: "" },
	},
	data() {
		const query = this.$panel.view?.query || {};
		const state = this.parseState((key) => query[key]);
		return {
			period: state.period,
			date: state.date,
			from: state.from,
			to: state.to,
			filters: state.filters,
			interval: this.stateInterval(state),
			comparison: state.comparison,
			matchDow: state.matchDow,
			compareFrom: state.compareFrom,
			compareTo: state.compareTo,
			metric: "visitors",
			kpis: {},
			series: [],
			comparisonSeries: [],
			realtime: null,
			loadingChart: false,
			pollTimer: null,
		};
	},
	computed: {
		locale() {
			return this.$panel.translation?.code || "en";
		},
		filterQuery() {
			return encodeFilters(this.filters) || "";
		},
		yesterdayStr() {
			return yesterdayYmd();
		},
		menuItems() {
			return [
				{ key: "today", period: "day", hotkey: "D" },
				{ key: "yesterday", period: "day", anchor: "yesterday", hotkey: "E" },
				{ divider: true },
				{ key: "7d", period: "7d", hotkey: "W" },
				{ key: "28d", period: "28d", hotkey: "F" },
				{ key: "30d", period: "30d" },
				{ key: "91d", period: "91d", hotkey: "N" },
				{ divider: true },
				{ key: "month", period: "month", hotkey: "M" },
				{ key: "6mo", period: "6mo" },
				{ key: "12mo", period: "12mo", hotkey: "L" },
				{ key: "year", period: "year", hotkey: "Y" },
				{ divider: true },
				{ key: "all", period: "all", hotkey: "A" },
				{ divider: true },
				{ key: "custom", period: "custom", custom: true, hotkey: "C" },
				...(this.comparison === null && this.period !== "all"
					? [{ divider: true }, { key: "compare", compare: true, hotkey: "X" }]
					: []),
			];
		},
		compareModes() {
			const t = (key) => this.$t("medienbaecker.plausibly." + key);
			return {
				previous_period: t("previousPeriod"),
				year_over_year: t("compare.yearOverYear"),
				custom: t("compare.customPeriod"),
			};
		},
		compareLabel() {
			const vs = this.$t("medienbaecker.plausibly.compare.vs");
			if (this.comparison === "custom" && this.compareFrom && this.compareTo) {
				return `${vs} ${rangeLabel(this.compareFrom, this.compareTo, this.locale)}`;
			}
			return `${vs} ${this.compareModes[this.comparison] || ""}`;
		},
		prevDisabled() {
			return !STEP[this.period];
		},
		nextDisabled() {
			return !STEP[this.period] || this.date === null;
		},
		periodLabel() {
			if (this.period === "custom" && this.from && this.to)
				return rangeLabel(this.from, this.to, this.locale);
			if (this.date === null) return this.periodName(this.period);
			if (this.period === "day" && this.date === this.yesterdayStr)
				return this.$t("medienbaecker.plausibly.yesterday");
			return navLabel(this.period, this.date, this.locale);
		},
		realtimeLabel() {
			const key = this.realtime === 1 ? "currentVisitors" : "currentVisitors.plural";
			return this.$t("medienbaecker.plausibly." + key, { count: this.realtime });
		},
		cards() {
			const t = (key) => this.$t("medienbaecker.plausibly." + key);
			const visitors = { key: "visitors", header: t("column.visitors") };

			return {
				sources: [
					{ name: "sources", label: t("sources"), dimension: "visit:source", icon: "favicon", labelHeader: t("column.source"), metrics: ["visitors"], columns: [visitors] },
					{ name: "channels", label: t("channels"), dimension: "visit:channel", icon: null, labelHeader: t("column.channel"), metrics: ["visitors"], columns: [visitors] },
					{ name: "utm", label: t("utm"), dimension: "visit:utm_campaign", icon: null, labelHeader: t("column.campaign"), metrics: ["visitors"], columns: [visitors] },
				],
				pages: [
					{
						name: "top", label: t("pages"), dimension: "event:page", icon: null, labelHeader: t("column.page"),
						metrics: ["visitors", "pageviews", "bounce_rate"],
						columns: [visitors, { key: "pageviews", header: t("column.pageviews") }, { key: "bounce_rate", header: t("column.bounceRate") }],
					},
					{ name: "entry", label: t("entryPages"), dimension: "visit:entry_page", icon: null, labelHeader: t("column.page"), metrics: ["visitors"], columns: [visitors] },
					{ name: "exit", label: t("exitPages"), dimension: "visit:exit_page", icon: null, labelHeader: t("column.page"), metrics: ["visitors"], columns: [visitors] },
				],
				locations: [
					{ name: "countries", label: t("countries"), dimension: "visit:country", icon: "flag", labelHeader: t("column.country"), metrics: ["visitors"], columns: [visitors] },
					{ name: "regions", label: t("regions"), dimension: "visit:region_name", icon: null, labelHeader: t("column.region"), metrics: ["visitors"], columns: [visitors] },
					{ name: "cities", label: t("cities"), dimension: "visit:city_name", icon: null, labelHeader: t("column.city"), metrics: ["visitors"], columns: [visitors] },
				],
				devices: [
					{ name: "browser", label: t("browser"), dimension: "visit:browser", icon: null, labelHeader: t("column.browser"), metrics: ["visitors"], columns: [visitors] },
					{ name: "os", label: t("os"), dimension: "visit:os", icon: null, labelHeader: t("column.os"), metrics: ["visitors"], columns: [visitors] },
					{ name: "size", label: t("screenSize"), dimension: "visit:device", icon: null, labelHeader: t("column.screenSize"), metrics: ["visitors"], columns: [visitors] },
				],
				goals: [
					{
						name: "goals", label: t("goals"), dimension: "event:goal", icon: null, labelHeader: t("column.goal"),
						metrics: ["visitors", "events", "conversion_rate"],
						columns: [
							{ key: "visitors", header: t("column.uniques") },
							{ key: "events", header: t("column.total") },
							{ key: "conversion_rate", header: t("column.cr") },
						],
					},
				],
			};
		},
	},
	created() {
		this.loadKpis();
		this.loadChart();
		this.loadRealtime();
		this.pollTimer = setInterval(this.loadRealtime, 15000);
		window.addEventListener("popstate", this.onPopState);
		window.addEventListener("keydown", this.onKeydown);
	},
	beforeUnmount() {
		clearInterval(this.pollTimer);
		window.removeEventListener("popstate", this.onPopState);
		window.removeEventListener("keydown", this.onKeydown);
	},
	methods: {
		periodName(p) {
			return this.$t("medienbaecker.plausibly.period." + p);
		},
		parseState(get) {
			const ymd = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v || "");
			let period = get("period");
			let from = null;
			let to = null;
			if (period === "custom") {
				from = get("from");
				to = get("to");
				if (!ymd(from) || !ymd(to) || from > to) {
					period = "28d";
					from = to = null;
				}
			} else if (!PERIODS.includes(period)) {
				period = "28d";
			}
			let comparison = ["previous_period", "year_over_year", "custom"].includes(get("comparison"))
				? get("comparison")
				: null;
			let compareFrom = null;
			let compareTo = null;
			if (comparison === "custom") {
				compareFrom = get("compare_from");
				compareTo = get("compare_to");
				if (!ymd(compareFrom) || !ymd(compareTo) || compareFrom > compareTo) {
					comparison = null;
					compareFrom = compareTo = null;
				}
			}
			if (period === "all") comparison = null;
			return {
				period,
				date: ymd(get("date")) ? get("date") : null,
				from,
				to,
				comparison,
				matchDow: get("match_day_of_week") !== "0",
				compareFrom,
				compareTo,
				filters: decodeFilters(get("filters")),
			};
		},
		stateInterval(state) {
			const span = state.from && state.to ? spanDays(state.from, state.to) : null;
			return intervalFor(state.period, span);
		},
		resolveAnchor(item) {
			return item.anchor === "yesterday" ? this.yesterdayStr : null;
		},
		isCurrent(item) {
			return this.period === item.period && this.date === this.resolveAnchor(item);
		},
		itemLabel(item) {
			if (item.compare) return this.$t("medienbaecker.plausibly.compare");
			if (item.anchor === "yesterday") return this.$t("medienbaecker.plausibly.yesterday");
			return this.periodName(item.period);
		},
		selectItem(item) {
			if (item.custom) {
				this.openRangeDialog();
				return;
			}
			if (item.compare) {
				this.setComparison("previous_period");
				return;
			}
			const anchor = this.resolveAnchor(item);
			if (item.period === this.period && this.date === anchor) return;
			this.period = item.period;
			this.date = anchor;
			this.from = this.to = null;
			if (item.period === "all") this.comparison = null;
			this.interval = intervalFor(item.period);
			this.updateUrl();
			this.loadKpis();
			this.loadChart();
		},
		openRangeDialog() {
			this.$panel.dialog.open({
				component: "k-plausibly-range-dialog",
				props: {
					initialFrom: this.from,
					initialTo: this.to,
				},
				on: {
					submit: ({ from, to }) => {
						this.$panel.dialog.close();
						this.setCustomRange(from, to);
					},
				},
			});
		},
		setCustomRange(from, to) {
			this.period = "custom";
			this.date = null;
			this.from = from;
			this.to = to;
			this.interval = intervalFor("custom", spanDays(from, to));
			this.updateUrl();
			this.loadKpis();
			this.loadChart();
		},
		setComparison(mode) {
			this.comparison = mode;
			if (mode !== "custom") {
				this.compareFrom = this.compareTo = null;
			}
			this.updateUrl();
			this.loadKpis();
			this.loadChart();
		},
		toggleMatchDow() {
			this.matchDow = !this.matchDow;
			this.updateUrl();
			this.loadKpis();
			this.loadChart();
		},
		openCompareRangeDialog() {
			this.$panel.dialog.open({
				component: "k-plausibly-range-dialog",
				props: {
					initialFrom: this.compareFrom,
					initialTo: this.compareTo,
				},
				on: {
					submit: ({ from, to }) => {
						this.$panel.dialog.close();
						this.compareFrom = from;
						this.compareTo = to;
						this.setComparison("custom");
					},
				},
			});
		},
		shiftPeriod(delta) {
			const next = shiftAnchor(this.period, this.date, delta);
			if (next === undefined) return;
			this.date = next;
			this.updateUrl();
			this.loadKpis();
			this.loadChart();
		},
		setMetric(metric) {
			this.metric = metric;
			this.loadChart();
		},
		onChartSelect(date) {
			if (this.interval === "hour") return; // nothing finer to drill into
			const period = this.interval === "month" ? "month" : "day";
			this.period = period;
			this.date = date;
			this.from = this.to = null;
			this.interval = intervalFor(period);
			this.updateUrl();
			this.loadKpis();
			this.loadChart();
		},
		updateUrl() {
			const url = new URL(window.location.href);
			url.searchParams.set("period", this.period);
			if (this.date) url.searchParams.set("date", this.date);
			else url.searchParams.delete("date");
			if (this.period === "custom") {
				url.searchParams.set("from", this.from);
				url.searchParams.set("to", this.to);
			} else {
				url.searchParams.delete("from");
				url.searchParams.delete("to");
			}
			if (this.comparison) {
				url.searchParams.set("comparison", this.comparison);
				if (this.matchDow) url.searchParams.delete("match_day_of_week");
				else url.searchParams.set("match_day_of_week", "0");
			} else {
				url.searchParams.delete("comparison");
				url.searchParams.delete("match_day_of_week");
			}
			if (this.comparison === "custom") {
				url.searchParams.set("compare_from", this.compareFrom);
				url.searchParams.set("compare_to", this.compareTo);
			} else {
				url.searchParams.delete("compare_from");
				url.searchParams.delete("compare_to");
			}
			if (this.filterQuery) url.searchParams.set("filters", this.filterQuery);
			else url.searchParams.delete("filters");
			// pushState so back/forward steps through navigated views (see onPopState).
			window.history.pushState({}, "", url);
		},
		onKeydown(event) {
			if (event.metaKey || event.ctrlKey || event.altKey) return;
			if (this.$panel.dialog.isOpen) return;
			if (event.target.closest?.("input, textarea, select, [contenteditable]")) return;
			const key = event.key?.toUpperCase();
			if (key === "X") {
				if (this.period !== "all") {
					this.setComparison(this.comparison ? null : "previous_period");
				}
				return;
			}
			const item = this.menuItems.find((i) => i.hotkey === key && !i.compare);
			if (item) this.selectItem(item);
		},
		onPopState() {
			// Ignore back/forward that leaves this view (the Panel handles it).
			if (!window.location.pathname.endsWith("/plausibly")) return;
			const params = new URL(window.location.href).searchParams;
			const state = this.parseState((key) => params.get(key));
			const filterQuery = encodeFilters(state.filters) || "";
			if (
				state.period === this.period &&
				state.date === this.date &&
				state.from === this.from &&
				state.to === this.to &&
				state.comparison === this.comparison &&
				state.matchDow === this.matchDow &&
				state.compareFrom === this.compareFrom &&
				state.compareTo === this.compareTo &&
				filterQuery === this.filterQuery
			) {
				return;
			}
			this.period = state.period;
			this.date = state.date;
			this.from = state.from;
			this.to = state.to;
			this.comparison = state.comparison;
			this.matchDow = state.matchDow;
			this.compareFrom = state.compareFrom;
			this.compareTo = state.compareTo;
			this.filters = state.filters;
			this.interval = this.stateInterval(state);
			this.loadKpis();
			this.loadChart();
		},
		// one filter per dimension; clicking the filtered value again removes it
		toggleFilter(filter) {
			const index = this.filters.findIndex((f) => f.dimension === filter.dimension);
			const existing = index !== -1 ? this.filters[index] : null;
			if (existing && existing.op === filter.op && existing.values.join() === filter.values.join()) {
				this.filters.splice(index, 1);
			} else if (existing) {
				this.filters.splice(index, 1, filter);
			} else {
				this.filters.push(filter);
			}
			this.applyFilters();
		},
		applyFilters() {
			this.updateUrl();
			this.loadKpis();
			this.loadChart();
		},
		comparisonParams() {
			if (!this.comparison) return {};
			return {
				comparison: this.comparison,
				match_day_of_week: this.matchDow ? "1" : "0",
				...(this.comparison === "custom"
					? { compare_from: this.compareFrom, compare_to: this.compareTo }
					: {}),
			};
		},
		async loadKpis() {
			const token = (this.kpiToken = Symbol());
			try {
				const kpis = await fetchData(this.$api, "plausible/aggregate", {
					period: this.period,
					...(this.date ? { date: this.date } : {}),
					...(this.period === "custom" ? { from: this.from, to: this.to } : {}),
					...(this.filterQuery ? { filters: this.filterQuery } : {}),
					...this.comparisonParams(),
				});
				if (token !== this.kpiToken) return; // superseded by a newer request
				this.kpis = kpis;
			} catch (e) {
				if (token !== this.kpiToken) return;
				this.$panel.notification.error(e.message || this.$t("medienbaecker.plausibly.error"));
			}
		},
		async loadChart() {
			const token = (this.chartToken = Symbol());
			this.loadingChart = true;
			try {
				const data = await fetchData(this.$api, "plausible/timeseries", {
					period: this.period,
					metric: this.metric,
					interval: this.interval,
					...(this.date ? { date: this.date } : {}),
					...(this.period === "custom" ? { from: this.from, to: this.to } : {}),
					...(this.filterQuery ? { filters: this.filterQuery } : {}),
					...this.comparisonParams(),
				});
				if (token !== this.chartToken) return; // superseded by a newer request
				// comparison first: Chart re-renders on the series watcher
				this.comparisonSeries = Array.isArray(data) ? [] : data.comparison || [];
				this.series = Array.isArray(data) ? data : data.series || [];
			} catch (e) {
				if (token !== this.chartToken) return;
				this.$panel.notification.error(e.message || this.$t("medienbaecker.plausibly.error"));
				this.series = [];
				this.comparisonSeries = [];
			} finally {
				if (token === this.chartToken) this.loadingChart = false;
			}
		},
		async loadRealtime() {
			try {
				// silent: background poll, no global loading cursor/spinner
				this.realtime = await fetchData(this.$api, "plausible/realtime", {}, { silent: true });
			} catch (e) {
				/* keep last known value */
			}
		},
	},
};
</script>

<style>
.k-plausible-view {
	--plausible-accent-light: var(--color-blue-700);
	--plausible-accent-dark: var(--color-blue-300);
	--plausible-accent: light-dark(var(--plausible-accent-light), var(--plausible-accent-dark));

	--plausible-fill-hue: var(--color-blue-400);
	--plausible-fill: light-dark(color-mix(in srgb, var(--plausible-fill-hue), transparent 75%),
			color-mix(in srgb, var(--plausible-fill-hue), transparent 85%));
	--plausible-fill-soft: light-dark(color-mix(in srgb, var(--plausible-fill-hue), transparent 90%),
			color-mix(in srgb, var(--plausible-fill-hue), transparent 95%));

	--focus-outline-color: var(--color-focus, currentColor);
	--focus-outline-offset: 2px;
	--focus-outline-style: solid;
	--focus-outline-width: 2px;
}

.k-plausible-view :is(.k-plausible-tab, .k-plausible-filter-label, .k-plausible-more):focus-visible {
	outline-color: var(--focus-outline-color);
	outline-offset: var(--focus-outline-offset);
	outline-style: var(--focus-outline-style);
	outline-width: var(--focus-outline-width);
}

.k-plausible-view [aria-busy="true"] {
	opacity: 0.4;
}

.k-plausible-view .k-header-buttons {
	flex-wrap: wrap;
	flex-shrink: 1;
	align-items: center;
}

.k-plausible-live {
	display: inline-flex;
	align-items: center;
	gap: var(--spacing-1);
	height: var(--height-sm);
	padding-inline: var(--spacing-2);
	background: var(--item-color-back);
	border-radius: var(--rounded);
	font-size: var(--text-sm);
	white-space: nowrap;
	color: var(--color-text-dimmed);
}

.k-plausible-live-icon {
	color: var(--color-text-dimmed);
}

.k-plausible-live[data-active="true"] {
	color: var(--color-text);

	.k-plausible-live-icon {
		color: var(--plausible-accent);
	}
}

.k-plausible-period,
.k-plausible-compare {
	position: relative;
}

.k-plausible-period .k-dropdown-item .k-button-text {
	display: flex;
	align-items: center;
	flex-grow: 1;
}

.k-plausible-hotkey {
	margin-inline-start: auto;
	padding-inline-start: var(--spacing-6);
	background: none;
	color: var(--color-text-dimmed);
	font-size: var(--text-xs);
	font-family: var(--font-mono);
}

.k-plausible-chart-card {
	margin-block-start: 2px;
	padding: var(--spacing-6);
	background: var(--table-color-back);
	border: 1px solid var(--table-color-border);
	border-radius: var(--rounded);
}

.k-plausible-stats:not([data-wrapped="true"])[data-active-first="true"]+.k-plausible-chart-card {
	border-start-start-radius: 0;
}

.k-plausible-stats:not([data-wrapped="true"])[data-active-last="true"]+.k-plausible-chart-card {
	border-start-end-radius: 0;
}

.k-plausible-grid {
	--gap: var(--spacing-8);
	column-gap: var(--gap);
	margin-block-start: var(--gap);
}
</style>
