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

const trimTransparentCanvas = (canvas) => {
    const context = canvas.getContext("2d");
    if (!context) return canvas;
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
    let lastVisibleRow = -1;
    for (let row = canvas.height - 1; row >= 0 && lastVisibleRow < 0; row -= 1) {
        for (let column = 0; column < canvas.width; column += 1) {
            if (pixels[(row * canvas.width + column) * 4 + 3] > 0) {
                lastVisibleRow = row;
                break;
            }
        }
    }
    if (lastVisibleRow < 0 || lastVisibleRow >= canvas.height - 1) return canvas;
    const trimmed = document.createElement("canvas");
    trimmed.width = canvas.width;
    trimmed.height = lastVisibleRow + 1;
    trimmed.getContext("2d")?.drawImage(canvas, 0, 0);
    return trimmed;
};

export async function downloadChartCardPng({ element, chart, filename, omitSelectors = [], minWidth = 960, rangeText = null, afterRestore = null, fitContent = true }) {
    if (!element || !chart) return;

    const plot = element.querySelector(".candle-plot");
    const tableWidth = element.querySelector(".compare-table-wrap")?.scrollWidth || 0;
    const baseExportWidth = Math.max(element.clientWidth, Math.min(1800, tableWidth + 64));
    const exportWidth = Math.max(720, Math.round(baseExportWidth * 0.8));
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
    clone.style.height = "auto";
    clone.style.minHeight = "0";
    clone.style.overflow = "visible";
    clone.style.maxWidth = "none";
    clone.style.margin = "0";
    clone.querySelectorAll([
        ".candle-export-menu",
        ".candle-navigation",
        ".candle-metrics",
        ".theme-toggle",
        ".compare-settings-icon",
        ".compare-record-settings",
        ...omitSelectors,
    ].join(",")).forEach(node => node.remove());
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

    // The comparison footer sits directly at the capture boundary; leave a small
    // breathing room so the TradingView copyright line is never clipped.
    if (comparisonTable) clone.style.paddingBottom = "12px";

    const stage = document.createElement("div");
    stage.className = "candle-export-stage";
    stage.style.position = "fixed";
    stage.style.left = "-100000px";
    stage.style.top = "0";
    stage.style.width = `${exportWidth}px`;
    stage.appendChild(clone);
    document.body.appendChild(stage);
    const renderStyle = document.createElement("style");
    renderStyle.textContent = `
        .candle-export-stage img, body > div:last-child img { display: inline-block !important; }
        .candle-export-clone .candle-quote-team::before { content: none !important; }
        .candle-export-clone .candle-topline { font-size: 14px !important; }
        .candle-export-clone .candle-topline b { font-size: 13px !important; }
        .candle-export-clone .candle-eyebrow { font-size: 14px !important; }
        .candle-export-clone .candle-player h2 { font-size: 30px !important; }
        .candle-export-clone .candle-price strong { font-size: 42px !important; }
        .candle-export-clone .candle-price span { font-size: 15px !important; }
        .candle-export-clone .candle-price small { font-size: 13px !important; }
        .candle-export-clone .candle-metrics button { font-size: 16px !important; }
        .candle-export-clone .candle-legend, .candle-export-clone .candle-range { font-size: 13px !important; }
        .candle-export-clone .candle-range b { font-size: 14px !important; }
        .candle-export-clone .candle-navigation button { font-size: 16px !important; }
        .candle-export-clone .candle-detail-heading, .candle-export-clone .candle-summary-heading { font-size: 15px !important; }
        .candle-export-clone .candle-detail-heading span, .candle-export-clone .candle-summary-heading span { font-size: 13px !important; }
        .candle-export-clone .candle-values span, .candle-export-clone .candle-summary-values span { font-size: 13px !important; }
        .candle-export-clone .candle-values strong, .candle-export-clone .candle-summary-values strong { font-size: 21px !important; }
        .candle-export-clone .candle-atbats { font-size: 13px !important; }
        .candle-export-clone .candle-atbats > div > span { font-size: 13px !important; }
        .candle-export-clone .candle-footnote { font-size: 13px !important; }
        .candle-export-clone .compare-header h2 { font-size: 33px !important; }
        .candle-export-clone .compare-metric-title { font-size: 27px !important; }
        .candle-export-clone .compare-legend { font-size: 13px !important; }
        .candle-export-clone .compare-legend > div > strong { font-size: 15px !important; }
        .candle-export-clone .compare-table { font-size: 15px !important; }
        .candle-export-clone .compare-table th, .candle-export-clone .compare-table td { font-size: 15px !important; }
        .candle-export-clone .compare-rank-guide { font-size: 13px !important; }
    `;
    document.head.appendChild(renderStyle);

    try {
        await document.fonts?.ready;
        await Promise.all([...clone.querySelectorAll("img")].map(image => image.complete
            ? Promise.resolve() : new Promise(resolve => {
                image.addEventListener("load", resolve, { once: true });
                image.addEventListener("error", resolve, { once: true });
            })));
        // html2canvas stretches <img> content instead of applying object-fit.
        // A cover-sized background preserves the same centered avatar crop.
        clone.querySelectorAll(".candle-avatar img, .compare-legend-avatar img, .compare-player-avatar img").forEach(image => {
            const avatar = document.createElement("div");
            avatar.className = "candle-export-avatar-image";
            Object.assign(avatar.style, {
                width: "100%", height: "100%",
                backgroundImage: `url(${JSON.stringify(image.currentSrc || image.src)})`,
                backgroundSize: "cover", backgroundPosition: "center",
                backgroundRepeat: "no-repeat",
            });
            image.replaceWith(avatar);
        });
        await nextPaint();
        // Render the watermark as a real, explicitly sized layer. html2canvas's
        // generated ::before node does not reliably fill an inset-sized header.
        const sourceQuote = element.querySelector(".candle-quote-team");
        const clonedQuote = clone.querySelector(".candle-quote-team");
        if (sourceQuote && clonedQuote) {
            const watermarkStyle = getComputedStyle(sourceQuote, "::before");
            const bounds = clonedQuote.getBoundingClientRect();
            const watermark = document.createElement("div");
            watermark.className = "candle-export-team-watermark";
            watermark.setAttribute("aria-hidden", "true");
            Object.assign(watermark.style, {
                position: "absolute", left: "0", top: "0",
                width: `${bounds.width}px`, height: `${bounds.height}px`,
                zIndex: "0", pointerEvents: "none",
                backgroundImage: watermarkStyle.backgroundImage,
                backgroundSize: watermarkStyle.backgroundSize,
                backgroundPosition: watermarkStyle.backgroundPosition,
                backgroundRepeat: watermarkStyle.backgroundRepeat,
                opacity: watermarkStyle.opacity,
            });
            clonedQuote.prepend(watermark);
            await nextPaint();
        }
        const captureWidth = Math.ceil(clone.getBoundingClientRect().width);
        const captureHeight = Math.ceil(clone.getBoundingClientRect().height) + (comparisonTable ? 64 : 0);
        const renderedCanvas = await html2canvas(clone, {
            backgroundColor: null,
            logging: false,
            onclone: (clonedDocument) => {
                clonedDocument.querySelectorAll("img").forEach(image => {
                    image.style.setProperty("display", "inline-block", "important");
                });
                clonedDocument.querySelectorAll(".candle-export-avatar-image").forEach(avatar => {
                    avatar.style.width = `${avatar.parentElement.clientWidth}px`;
                    avatar.style.height = `${avatar.parentElement.clientHeight}px`;
                });
                const q = clonedDocument.querySelector('.candle-export-clone .candle-quote-team');
                const layer = q?.querySelector('.candle-export-team-watermark');
                if (layer) {
                    // windowWidth can switch responsive padding after cloning.
                    // Size the layer against this final capture layout.
                    const bounds = q.getBoundingClientRect();
                    layer.style.width = `${bounds.width}px`;
                    layer.style.height = `${bounds.height}px`;
                }
            },
            scale: 2,
            useCORS: true,
            width: captureWidth,
            height: captureHeight,
            windowWidth: exportWidth,
        });
        const canvas = trimTransparentCanvas(renderedCanvas);
        clickDownload(canvas.toDataURL("image/png"), filename);
    } finally {
        renderStyle.remove();
        stage.remove();
    }
}
