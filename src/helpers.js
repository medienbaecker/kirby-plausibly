// 1056 -> "1k", 2246 -> "2.2k", 1_200_000 -> "1.2M" (truncated, not rounded)
export function numberFormatter(num) {
	num = Number(num) || 0;
	// one truncated decimal, dropped for whole numbers and values >= 100
	const short = (n) =>
		n === Math.floor(n) || n >= 100 ? Math.floor(n) : Math.floor(n * 10) / 10;
	if (num >= 1e6) return short(num / 1e6) + "M";
	if (num >= 1e3) return short(num / 1e3) + "k";
	return String(num);
}

// 97 -> "1m 37s", 3700 -> "1h 1m 40s"
export function durationFormatter(seconds) {
	seconds = Math.round(Number(seconds) || 0);
	const h = Math.floor(seconds / 3600);
	const m = Math.floor((seconds % 3600) / 60);
	const s = seconds % 60;
	let res = "";
	if (h) res += `${h}h `;
	if (m) res += `${m}m `;
	if (s || !res) res += `${s}s`;
	return res.trim();
}

export function formatMetric(metric, value) {
	if (value == null) value = 0;
	switch (metric) {
		case "bounce_rate":
			return Math.round(value) + "%";
		case "conversion_rate":
			return Math.round(value * 10) / 10 + "%";
		case "views_per_visit":
			return (Math.round(value * 100) / 100).toFixed(2);
		case "visit_duration":
		case "time_on_page":
			return durationFormatter(value);
		default:
			return numberFormatter(value);
	}
}

// The six top-line KPIs in dashboard order; `up` = is a rising value good?
export const KPIS = [
	{ key: "visitors", up: true },
	{ key: "visits", up: true },
	{ key: "pageviews", up: true },
	{ key: "views_per_visit", up: true },
	{ key: "bounce_rate", up: false },
	{ key: "visit_duration", up: true },
];

export const PERIODS = [
	"day",
	"7d",
	"28d",
	"30d",
	"91d",
	"month",
	"6mo",
	"12mo",
	"year",
	"all",
];

export function intervalFor(period) {
	switch (period) {
		case "day":
			return "hour";
		case "6mo":
		case "12mo":
		case "year":
		case "all":
			return "month";
		default:
			return "day";
	}
}

// How far one ‹ › step moves the anchor, per period. null = not navigable.
export const STEP = {
	day: { unit: "day", n: 1 },
	"7d": { unit: "day", n: 7 },
	"28d": { unit: "day", n: 28 },
	"30d": { unit: "day", n: 30 },
	"91d": { unit: "day", n: 91 },
	month: { unit: "month", n: 1 },
	"6mo": { unit: "month", n: 6 },
	"12mo": { unit: "month", n: 12 },
	year: { unit: "year", n: 1 },
	all: null,
};

function parseYmd(s) {
	const [y, m, d] = s.split("-").map(Number);
	return new Date(y, m - 1, d);
}

function formatYmd(date) {
	const y = date.getFullYear();
	const m = String(date.getMonth() + 1).padStart(2, "0");
	const d = String(date.getDate()).padStart(2, "0");
	return `${y}-${m}-${d}`;
}

function startOfToday() {
	const now = new Date();
	return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

// Step a date by whole days/months/years. Month/year steps first snap to the
// 1st so repeated stepping never skips a short month (e.g. Mar 31 → Feb).
function addStep(date, unit, amount) {
	const d = new Date(date.getTime());
	if (unit === "day") d.setDate(d.getDate() + amount);
	else if (unit === "month") {
		d.setDate(1);
		d.setMonth(d.getMonth() + amount);
	} else if (unit === "year") d.setFullYear(d.getFullYear() + amount);
	return d;
}

// "Now" anchor per period, mirroring the backend: rolling windows end
// yesterday, day is today, month/year key off the current month/year.
function nowAnchor(period) {
	const t = startOfToday();
	const step = STEP[period];
	if (period === "day" || !step) return t;
	if (step.unit === "day") return addStep(t, "day", -1);
	if (step.unit === "month") return new Date(t.getFullYear(), t.getMonth(), 1);
	return new Date(t.getFullYear(), 0, 1); // year
}

export function yesterdayYmd() {
	return formatYmd(addStep(startOfToday(), "day", -1));
}

// Move the anchor by `delta` steps. Returns the new anchor as "Y-m-d", or
// null when the step reaches/passes "now" (snap back to the live view), or
// undefined when the period can't be navigated (all time).
export function shiftAnchor(period, date, delta) {
	const step = STEP[period];
	if (!step) return undefined;
	const now = nowAnchor(period);
	const base = date ? parseYmd(date) : now;
	const next = addStep(base, step.unit, delta * step.n);
	if (next >= now) return null;
	return formatYmd(next);
}

// Human label for a navigated (non-live) view, e.g. "23 Jun 2026",
// "June 2026", "2026", "27 May – 23 Jun 2026", "Jan – Jun 2026".
export function navLabel(period, date, locale) {
	const loc = locale || "en";
	const end = parseYmd(date);
	const fmt = (opts) => new Intl.DateTimeFormat(loc, opts).format(end);

	if (period === "day")
		return fmt({ day: "numeric", month: "short", year: "numeric" });
	if (period === "month") return fmt({ month: "long", year: "numeric" });
	if (period === "year") return String(end.getFullYear());

	const step = STEP[period];
	if (step.unit === "month") {
		const first = new Date(end.getFullYear(), end.getMonth(), 1);
		const start = addStep(first, "month", -(step.n - 1));
		const ms = new Intl.DateTimeFormat(loc, { month: "short" }).format(start);
		const me = new Intl.DateTimeFormat(loc, { month: "short", year: "numeric" }).format(end);
		return `${ms} – ${me}`;
	}

	// rolling day windows (7d / 28d / 30d / 91d)
	const start = addStep(end, "day", -(step.n - 1));
	const ds = new Intl.DateTimeFormat(loc, { day: "numeric", month: "short" }).format(start);
	const de = new Intl.DateTimeFormat(loc, { day: "numeric", month: "short", year: "numeric" }).format(end);
	return `${ds} – ${de}`;
}

// ISO 3166-1 alpha-2 -> flag emoji
export function countryFlag(code) {
	if (!code || code.length !== 2) return "🏳️";
	const base = 0x1f1e6;
	return String.fromCodePoint(
		...[...code.toUpperCase()].map((c) => base + c.charCodeAt(0) - 65)
	);
}

// Localised country name from its ISO code
function countryName(code, locale) {
	try {
		return new Intl.DisplayNames([locale], { type: "region" }).of(code) || code;
	} catch (e) {
		return code;
	}
}

export function toTable(rows, opts) {
	const {
		columnDefs,
		labelHeader,
		iconType = null,
		faviconBase = "",
		notSet = "(not set)",
		locale = "en",
	} = opts;
	const primary = columnDefs[0].key;
	const max = rows.reduce((m, r) => Math.max(m, r.metrics[primary] || 0), 0);

	const columns = {
		label: {
			label: labelHeader,
			type: "barlabel",
			iconType,
			faviconBase,
			mobile: true,
		},
	};
	for (const col of columnDefs) {
		columns[col.key] = {
			label: col.header,
			type: "text",
			align: "right",
			mobile: true,
		};
	}

	const tableRows = rows.map((r) => {
		const label =
			r.label === ""
				? notSet
				: iconType === "flag"
				? countryName(r.label, locale)
				: r.label;
		// Bar width: Plausible's own share-of-total when present; for goals
		// (no percentage) the conversion rate, matching Plausible's goal bars;
		// otherwise share of the largest value in the column.
		const share = r.metrics.percentage ?? r.metrics.conversion_rate;
		const bar =
			share != null ? share : max ? ((r.metrics[primary] || 0) / max) * 100 : 0;
		const out = {
			label,
			_raw: r.label,
			_bar: bar + "%",
			_page: r.page ?? null,
		};
		for (const col of columnDefs) {
			out[col.key] = formatMetric(col.key, r.metrics[col.key]);
		}
		return out;
	});

	return { columns, rows: tableRows };
}
export async function fetchData(api, path, query, options) {
	const res = await api.get(path, query, options);
	if (res && res.error) throw new Error(res.error);
	return res ? res.data : null;
}
