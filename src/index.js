import PlausibleView from "./components/PlausibleView.vue";
import BarCell from "./components/BarCell.vue";
import LicenseDialog from "./components/LicenseDialog.vue";
import RangeDialog from "./components/RangeDialog.vue";

panel.plugin("medienbaecker/plausibly", {
	components: {
		"k-plausible-view": PlausibleView,
		"k-table-barlabel-cell": BarCell,
		"k-plausibly-license-dialog": LicenseDialog,
		"k-plausibly-range-dialog": RangeDialog,
	},
});
