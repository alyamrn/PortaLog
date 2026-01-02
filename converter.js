
const r = v => Math.round(v * 100) / 100;

document.addEventListener("input", () => {

    if (lenInput.value) {
        let v = +lenInput.value;
        lenResult.innerText = ({
            m2ft: r(v * 3.28084) + " ft",
            ft2m: r(v / 3.28084) + " m",
            m2nm: r(v / 1852) + " NM",
            nm2m: r(v * 1852) + " m"
        })[lenType.value];
    }

    if (spdInput.value) {
        let v = +spdInput.value;
        spdResult.innerText = ({
            knot2kmh: r(v * 1.852) + " km/h",
            kmh2knot: r(v / 1.852) + " kn",
            knot2ms: r(v * 0.51444) + " m/s",
            ms2knot: r(v / 0.51444) + " kn"
        })[spdType.value];
    }

    if (wtInput.value) {
        let v = +wtInput.value;
        wtResult.innerText = ({
            kg2lb: r(v * 2.20462) + " lbs",
            lb2kg: r(v / 2.20462) + " kg",
            kg2mt: r(v / 1000) + " MT",
            mt2kg: r(v * 1000) + " kg"
        })[wtType.value];
    }

    if (volInput.value) {
        let v = +volInput.value;
        volResult.innerText = ({
            l2m3: r(v / 1000) + " m³",
            m32l: r(v * 1000) + " L",
            m32bbl: r(v / 0.159) + " bbl",
            bbl2m3: r(v * 0.159) + " m³"
        })[volType.value];
    }

    if (fuelInput.value && densityInput.value) {
        let v = +fuelInput.value, d = +densityInput.value;
        fuelResult.innerText = fuelType.value === "l2mt"
            ? r((v * d) / 1000) + " MT"
            : r((v * 1000) / d) + " L";
    }

    if (prsInput.value) {
        let v = +prsInput.value;
        prsResult.innerText = ({
            bar2psi: r(v * 14.5038) + " PSI",
            psi2bar: r(v / 14.5038) + " Bar",
            bar2kpa: r(v * 100) + " kPa",
            kpa2bar: r(v / 100) + " Bar"
        })[prsType.value];
    }

    if (pwrInput.value) {
        let v = +pwrInput.value;
        pwrResult.innerText = ({
            kw2hp: r(v * 1.34102) + " HP",
            hp2kw: r(v / 1.34102) + " kW"
        })[pwrType.value];
    }

    if (flowInput.value) {
        let v = +flowInput.value;
        flowResult.innerText = ({
            lph2m3h: r(v / 1000) + " m³/h",
            m3h2lph: r(v * 1000) + " L/h"
        })[flowType.value];
    }

    if (angInput.value) {
        let v = +angInput.value;
        angResult.innerText = angType.value === "deg2rad"
            ? r(v * Math.PI / 180) + " rad"
            : r(v * 180 / Math.PI) + " °";
    }

    if (tmpInput.value) {
        let v = +tmpInput.value;
        tmpResult.innerText = tmpType.value === "c2f"
            ? r(v * 9 / 5 + 32) + " °F"
            : r((v - 32) * 5 / 9) + " °C";
    }

    /* ================= COMMON ================= */
    function round(v, d = 2) {
        return Math.round(v * Math.pow(10, d)) / Math.pow(10, d);
    }

    function safeNum(val) {
        return val !== "" && !isNaN(val);
    }

    /* ================= EVENT BINDING ================= */
    document.addEventListener("DOMContentLoaded", () => {

        document.querySelectorAll(
            "#lenInput, #lenType, #spdInput, #spdType, #wtInput, #wtType, \
         #volInput, #volType, #fuelInput, #densityInput, #fuelType, \
         #prsInput, #prsType, #pwrInput, #pwrType, #flowInput, #flowType, \
         #angInput, #angType, #tmpInput, #tmpType, \
         #utcOffset, #timeStart, #timeEnd"
        ).forEach(el => el.addEventListener("input", calculateAll));

    });

    /* ================= MAIN CONTROLLER ================= */
    function calculateAll() {
        convertLength();
        convertSpeed();
        convertWeight();
        convertVolume();
        convertFuel();
        convertPressure();
        convertPower();
        convertFlow();
        convertAngle();
        convertTemperature();
        calcUTC();
        calcDuration();
    }

    /* ================= CONVERTERS ================= */

    function convertLength() {
        if (!safeNum(lenInput.value)) return lenResult.textContent = "";
        const v = +lenInput.value;
        const r = {
            m2ft: v * 3.28084,
            ft2m: v / 3.28084,
            m2nm: v / 1852,
            nm2m: v * 1852
        }[lenType.value];
        lenResult.textContent = round(r) + " " + lenType.options[lenType.selectedIndex].text.split("→")[1].trim();
    }

    function convertSpeed() {
        if (!safeNum(spdInput.value)) return spdResult.textContent = "";
        const v = +spdInput.value;
        const r = {
            knot2kmh: v * 1.852,
            kmh2knot: v / 1.852,
            knot2ms: v * 0.51444,
            ms2knot: v / 0.51444
        }[spdType.value];
        spdResult.textContent = round(r) + " " + spdType.options[spdType.selectedIndex].text.split("→")[1].trim();
    }

    function convertWeight() {
        if (!safeNum(wtInput.value)) return wtResult.textContent = "";
        const v = +wtInput.value;
        const r = {
            kg2lb: v * 2.20462,
            lb2kg: v / 2.20462,
            kg2mt: v / 1000,
            mt2kg: v * 1000
        }[wtType.value];
        wtResult.textContent = round(r) + " " + wtType.options[wtType.selectedIndex].text.split("→")[1].trim();
    }

    function convertVolume() {
        if (!safeNum(volInput.value)) return volResult.textContent = "";
        const v = +volInput.value;
        const r = {
            l2m3: v / 1000,
            m32l: v * 1000,
            m32bbl: v / 0.159,
            bbl2m3: v * 0.159
        }[volType.value];
        volResult.textContent = round(r) + " " + volType.options[volType.selectedIndex].text.split("→")[1].trim();
    }

    function convertFuel() {
        if (!safeNum(fuelInput.value) || !safeNum(densityInput.value)) return fuelResult.textContent = "";
        const v = +fuelInput.value;
        const d = +densityInput.value;
        const r = fuelType.value === "l2mt" ? (v * d) / 1000 : (v * 1000) / d;
        fuelResult.textContent = round(r) + (fuelType.value === "l2mt" ? " MT" : " L");
    }

    function convertPressure() {
        if (!safeNum(prsInput.value)) return prsResult.textContent = "";
        const v = +prsInput.value;
        const r = {
            bar2psi: v * 14.5038,
            psi2bar: v / 14.5038,
            bar2kpa: v * 100,
            kpa2bar: v / 100
        }[prsType.value];
        prsResult.textContent = round(r) + " " + prsType.options[prsType.selectedIndex].text.split("→")[1].trim();
    }

    function convertPower() {
        if (!safeNum(pwrInput.value)) return pwrResult.textContent = "";
        const v = +pwrInput.value;
        const r = {
            kw2hp: v * 1.34102,
            hp2kw: v / 1.34102
        }[pwrType.value];
        pwrResult.textContent = round(r) + " " + pwrType.options[pwrType.selectedIndex].text.split("→")[1].trim();
    }

    function convertFlow() {
        if (!safeNum(flowInput.value)) return flowResult.textContent = "";
        const v = +flowInput.value;
        const r = {
            lph2m3h: v / 1000,
            m3h2lph: v * 1000
        }[flowType.value];
        flowResult.textContent = round(r) + " " + flowType.options[flowType.selectedIndex].text.split("→")[1].trim();
    }

    function convertAngle() {
        if (!safeNum(angInput.value)) return angResult.textContent = "";
        const v = +angInput.value;
        const r = angType.value === "deg2rad" ? (v * Math.PI) / 180 : (v * 180) / Math.PI;
        angResult.textContent = round(r, 4) + (angType.value === "deg2rad" ? " rad" : " °");
    }

    function convertTemperature() {
        if (!safeNum(tmpInput.value)) return tmpResult.textContent = "";
        const v = +tmpInput.value;
        const r = tmpType.value === "c2f" ? (v * 9) / 5 + 32 : (v - 32) * 5 / 9;
        tmpResult.textContent = round(r) + (tmpType.value === "c2f" ? " °F" : " °C");
    }

    /* ================= TIME UTILITIES ================= */

    function calcUTC() {
        if (!safeNum(utcOffset.value)) return utcResult.textContent = "";
        const offset = +utcOffset.value;
        const now = new Date();
        const utc = new Date(now.getTime() + offset * 3600000);
        utcResult.textContent = "Local Time: " + utc.toLocaleTimeString();
    }

    function calcDuration() {
        if (!timeStart.value || !timeEnd.value) return durationResult.textContent = "";
        const start = new Date("1970-01-01T" + timeStart.value + ":00");
        const end = new Date("1970-01-01T" + timeEnd.value + ":00");
        let diff = (end - start) / 60000;
        if (diff < 0) diff += 1440; // overnight watch
        durationResult.textContent = diff + " minutes";
    }
});

