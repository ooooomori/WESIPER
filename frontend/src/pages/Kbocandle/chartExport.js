import html2canvas from "html2canvas";

const csvCell = (value) => `"${String(value ?? "").replace(/"/g, '""')}"`;

const clickDownload = (href, filename) => {
    const link = document.createElement("a");
    link.href = href;
    link.download = filename;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
};

export const exportFileStem = (...parts) => parts
    .filter(Boolean)
    .join("_")
    .replace(/[<>:"/\\|?*\u0000-\u001f]/g, "-")
    .replace(/\s+/g, "_");

export function downloadCsv(filename, headers, rows) {
    const csv = [headers, ...rows].map((row) => row.map(csvCell).join(",")).join("\r\n");
    const blob = new Blob(["\ufeff", csv], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    clickDownload(url, filename);
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const nextPaint = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));

export async function downloadChartCardPng({ element, chart, filename, omitSelectors = [], minWidth = 960, rangeText = null, afterRestore = null, fitContent = true }) {
    if (!element || !chart) return;

    const plot = element.querySelector(".candle-plot");
    const tableWidth = element.querySelector(".compare-table-wrap")?.scrollWidth || 0;
    const exportWidth = Math.max(minWidth, element.clientWidth, Math.min(1800, tableWidth + 64));
    const plotHeight = plot?.clientHeight || 480;
    const visibleRange = chart.timeScale().getVisibleLogicalRange();
    let chartImage;

    try {
        chart.applyOptions({ autoSize: false, width: exportWidth, height: plotHeight });
        if (fitContent) chart.timeScale().fitContent();
        else if (visibleRange) chart.timeScale().setVisibleLogicalRange(visibleRange);
        await nextPaint();
        chartImage = chart.takeScreenshot(true, false).toDataURL("image/png");
    } finally {
        chart.applyOptions({ autoSize: true });
        await nextPaint();
        if (visibleRange) chart.timeScale().setVisibleLogicalRange(visibleRange);
        await nextPaint();
        afterRestore?.();
    }

    const clone = element.cloneNode(true);
    clone.classList.add("candle-export-clone");
    clone.style.width = `${exportWidth}px`;
    clone.style.maxWidth = "none";
    clone.style.margin = "0";
    clone.querySelectorAll([".candle-export-menu", ...omitSelectors].join(",")).forEach(node => node.remove());
    clone.querySelectorAll(".candle-extrema-label, .candle-help-tooltip").forEach(node => node.remove());
    if (rangeText) {
        const rangeParts = clone.querySelectorAll(".candle-range > span");
        if (rangeParts[0]) rangeParts[0].textContent = rangeText.dates;
        if (rangeParts[1]) rangeParts[1].textContent = rangeText.summary;
    }

    const clonedPlot = clone.querySelector(".candle-plot");
    if (clonedPlot) {
        clonedPlot.replaceChildren();
        clonedPlot.style.height = `${plotHeight}px`;
        const image = document.createElement("img");
        image.src = chartImage;
        image.alt = "";
        image.style.display = "block";
        image.style.width = "100%";
        image.style.height = "100%";
        clonedPlot.appendChild(image);
    }
    const tableWrap = clone.querySelector(".compare-table-wrap");
    if (tableWrap) {
        tableWrap.style.overflow = "visible";
        tableWrap.style.width = "100%";
        tableWrap.style.minWidth = "0";
        tableWrap.scrollLeft = 0;
    }
    const comparisonTable = clone.querySelector(".compare-table");
    if (comparisonTable) {
        comparisonTable.style.width = "100%";
        comparisonTable.style.minWidth = "0";
        comparisonTable.style.borderCollapse = "separate";
        comparisonTable.style.borderSpacing = "1px";
        comparisonTable.style.backgroundColor = clone.classList.contains("theme-light") ? "#d6e0ea" : "#2a3749";
        comparisonTable.querySelectorAll("th, td").forEach(cell => {
            cell.style.position = "static";
            cell.style.inset = "auto";
            cell.style.verticalAlign = "middle";
            cell.style.backgroundImage = "none";
            cell.style.border = "0";
        });
        const lightTheme = clone.classList.contains("theme-light");
        comparisonTable.querySelectorAll("tbody td").forEach(cell => {
            cell.style.position = "relative";
            cell.style.height = "38px";
            const value = cell.firstElementChild;
            if (!value) return;
            value.style.position = "absolute";
            value.style.left = "50%";
            value.style.top = "50%";
            value.style.transform = "translate(-50%, -50%)";
            value.style.zIndex = "1";
            value.style.whiteSpace = "nowrap";
        });
        comparisonTable.querySelectorAll(".compare-best, .compare-worst").forEach(value => {
            const best = value.classList.contains("compare-best");
            value.style.display = "block";
            value.style.boxSizing = "border-box";
            value.style.height = "24px";
            value.style.padding = "0 7px";
            value.style.lineHeight = "22px";
            value.style.textAlign = "center";
            value.style.boxShadow = "none";
            value.style.backgroundImage = "none";
            value.style.backgroundColor = lightTheme
                ? (best ? "#dff4ed" : "#fde7e9")
                : (best ? "#173e36" : "#42232a");
            value.style.border = `1px solid ${best ? "#42d5b2" : "#ef5b6b"}`;
        });
    }

    const stage = document.createElement("div");
    stage.className = "candle-export-stage";
    stage.style.position = "fixed";
    stage.style.left = "-100000px";
    stage.style.top = "0";
    stage.style.width = `${exportWidth}px`;
    stage.appendChild(clone);
    document.body.appendChild(stage);
    const renderStyle = document.createElement("style");
    renderStyle.textContent = ".candle-export-stage img, body > div:last-child img { display: inline-block !important; }";
    document.head.appendChild(renderStyle);

    try {
        await document.fonts?.ready;
        await Promise.all([...clone.querySelectorAll("img")].map(image => image.complete
            ? Promise.resolve() : new Promise(resolve => {
                image.addEventListener("load", resolve, { once: true });
                image.addEventListener("error", resolve, { once: true });
            })));
        const canvas = await html2canvas(clone, {
            backgroundColor: null,
            logging: false,
            onclone: (clonedDocument) => {
                clonedDocument.querySelectorAll("img").forEach(image => {
                    image.style.setProperty("display", "inline-block", "important");
                });
            },
            scale: 2,
            useCORS: true,
            width: clone.scrollWidth,
            height: clone.scrollHeight,
            windowWidth: exportWidth,
        });
        clickDownload(canvas.toDataURL("image/png"), filename);
    } finally {
        renderStyle.remove();
        stage.remove();
    }
}
