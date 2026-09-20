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

const loadBackgroundImage = async background => {
    const match = /^url\(["']?(.*?)["']?\)$/.exec(background);
    if (!match) return null;
    const image = new Image();
    image.crossOrigin = "anonymous";
    image.src = match[1];
    await image.decode();
    return image;
};

const centeredCoverImage = (image, width, height) => {
    const canvas = document.createElement("canvas");
    canvas.width = Math.ceil(width * 2);
    canvas.height = Math.ceil(height * 2);
    const scale = Math.max(canvas.width / image.naturalWidth, canvas.height / image.naturalHeight);
    const drawnWidth = image.naturalWidth * scale;
    const drawnHeight = image.naturalHeight * scale;
    canvas.getContext("2d").drawImage(image,
        (canvas.width - drawnWidth) / 2, (canvas.height - drawnHeight) / 2,
        drawnWidth, drawnHeight);
    return canvas.toDataURL("image/png");
};

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

const frameRoundedCanvas = (canvas, radiusCss, borderColor, scale) => {
    const framed = document.createElement("canvas");
    framed.width = canvas.width;
    framed.height = canvas.height;
    const context = framed.getContext("2d");
    if (!context) return canvas;

    const lineWidth = Math.max(1, scale);
    const inset = lineWidth / 2;
    const radius = Math.max(lineWidth, radiusCss * scale);
    const left = inset;
    const top = inset;
    const right = framed.width - inset;
    const bottom = framed.height - inset;
    const roundedRectPath = (x, y, width, height, r) => {
        const x2 = x + width;
        const y2 = y + height;
        context.beginPath();
        context.moveTo(x + r, y);
        context.lineTo(x2 - r, y);
        context.arcTo(x2, y, x2, y + r, r);
        context.lineTo(x2, y2 - r);
        context.arcTo(x2, y2, x2 - r, y2, r);
        context.lineTo(x + r, y2);
        context.arcTo(x, y2, x, y2 - r, r);
        context.lineTo(x, y + r);
        context.arcTo(x, y, x + r, y, r);
        context.closePath();
    };

    roundedRectPath(0, 0, framed.width, framed.height, radius);
    context.save();
    context.clip();
    context.drawImage(canvas, 0, 0);
    context.restore();
    const strokeRadius = Math.max(0, radius - inset);
    context.beginPath();
    context.moveTo(left, bottom - strokeRadius);
    context.arcTo(left, bottom, left + strokeRadius, bottom, strokeRadius);
    context.lineTo(right - strokeRadius, bottom);
    context.arcTo(right, bottom, right, bottom - strokeRadius, strokeRadius);
    context.lineWidth = lineWidth;
    context.strokeStyle = borderColor;
    context.stroke();
    return framed;
};

export async function downloadChartCardPng({ element, chart, filename, omitSelectors = [], minWidth = 960, rangeText = null, afterRestore = null, fitContent = true, getMarkers = null }) {
    if (!element || !chart) return;

    const plot = element.querySelector(".candle-plot");
    const tableWidth = element.querySelector(".compare-table-wrap")?.scrollWidth || 0;
    const baseExportWidth = Math.max(element.clientWidth, Math.min(1800, tableWidth + 64));
    const exportWidth = Math.max(720, Math.round(baseExportWidth * 0.8));
    const plotHeight = plot?.clientHeight || 480;
    const screenPlotWidth = plot?.clientWidth || exportWidth;
    const visibleRange = chart.timeScale().getVisibleLogicalRange();
    const terminalStyle = getComputedStyle(element);
    const terminalRadius = Number.parseFloat(terminalStyle.borderBottomLeftRadius) || 16;
    const terminalBorderColor = terminalStyle.borderBottomColor || "#293445";
    let chartImage;

    try {
        chart.applyOptions({ autoSize: false, width: exportWidth, height: plotHeight });
        if (fitContent) chart.timeScale().fitContent();
        else if (visibleRange) chart.timeScale().setVisibleLogicalRange(visibleRange);
        await nextPaint();
        const screenshot = chart.takeScreenshot(true, false);
        const context = screenshot.getContext("2d");
        const labels = getMarkers?.() || [];
        if (context && labels.length) {
            const light = element.classList.contains("theme-light");
            const fontFamily = getComputedStyle(element).fontFamily;
            context.save();
            context.scale(screenshot.width / exportWidth, screenshot.height / plotHeight);
            context.font = `600 12px ${fontFamily}`;
            context.textBaseline = "middle";
            labels.forEach(({ kind, x, y, text }) => {
                const high = kind === "high";
                const direction = high ? -1 : 1;
                const color = high ? (light ? "#cf2437" : "#ff6675") : (light ? "#1769c2" : "#5d9cff");
                const width = context.measureText(text).width + 10;
                const left = Math.max(2, Math.min(exportWidth - width - 2, x - width / 2));
                const centerY = Math.max(11, Math.min(plotHeight - 11, y + direction * 27));
                context.fillStyle = light ? "#f8fbff" : "#101722";
                context.fillRect(left, centerY - 10, width, 20);
                context.fillStyle = color;
                context.fillText(text, left + 5, centerY);
                // Keep the arrow tip anchored to the exact candle, even at chart edges.
                context.strokeStyle = color;
                context.lineWidth = 1;
                context.beginPath();
                context.moveTo(x, y + direction * 3);
                context.lineTo(x, y + direction * 16);
                context.moveTo(x - 4, y + direction * 8);
                context.lineTo(x, y + direction * 3);
                context.lineTo(x + 4, y + direction * 8);
                context.stroke();
            });
            context.restore();
        }
        chartImage = screenshot.toDataURL("image/png");
    } finally {
        // Switching autoSize back on does not itself notify Lightweight Charts
        // that the canvas was temporarily resized for export. Restore the real
        // on-screen dimensions first, then hand sizing back to ResizeObserver.
        chart.applyOptions({ autoSize: false, width: screenPlotWidth, height: plotHeight });
        await nextPaint();
        if (visibleRange) chart.timeScale().setVisibleLogicalRange(visibleRange);
        await nextPaint();
        chart.applyOptions({ autoSize: true });
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
        ".candle-footnote",
        ".theme-toggle",
        ".compare-settings-icon",
        ".compare-record-settings",
        ".candle-prediction-rank-button",
        ...omitSelectors,
    ].join(",")).forEach(node => node.remove());
    clone.querySelectorAll(".candle-extrema-label, .candle-help-tooltip").forEach(node => node.remove());
    const breakdownSelect = clone.querySelector(".candle-breakdown-heading select");
    if (breakdownSelect) {
        const sourceSelect = element.querySelector(".candle-breakdown-heading select");
        const label = document.createElement("span");
        label.className = "candle-breakdown-export-value";
        label.textContent = sourceSelect?.selectedOptions[0]?.textContent || breakdownSelect.selectedOptions[0]?.textContent || "기간별";
        breakdownSelect.replaceWith(label);
    }
    if (rangeText) {
        const rangeParts = clone.querySelectorAll(".candle-range > span");
        if (rangeParts[0]) rangeParts[0].textContent = rangeText.dates;
        if (rangeParts[1]) rangeParts[1].remove();
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
    stage.style.paddingBottom = comparisonTable ? "64px" : "16px";
    stage.style.background = "transparent";
    clone.style.boxShadow = "none";
    stage.appendChild(clone);
    document.body.appendChild(stage);
    const renderStyle = document.createElement("style");
    renderStyle.textContent = `
        .candle-export-stage img, body > div:last-child img { display: inline-block !important; }
        .candle-export-clone .candle-quote-team::before { content: none !important; }
        .candle-export-clone .compare-player-cell::before { content: none !important; }
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
        .candle-export-clone .candle-breakdown-heading { font-size: 15px !important; }
        .candle-export-clone .candle-breakdown-export-value { display: inline-flex; align-items: center; box-sizing: border-box; min-width: 84px; height: 28px; padding: 0 28px 0 9px; border: 1px solid #354358; border-radius: 7px; background: #202e40; color: #e7edf6; font-size: 11px; line-height: normal; white-space: nowrap; }
        .candle-export-clone.theme-light .candle-breakdown-export-value { border-color: #b8c8d8; background: #f8fbff; color: #102033; }
        .candle-export-clone .candle-period-summary, .candle-export-clone .compare-table-section { margin-top: 16px !important; }
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
        const cellWatermarks = await Promise.all([...element.querySelectorAll(".compare-player-cell")].map(async cell => {
            const style = getComputedStyle(cell, "::before");
            const image = await loadBackgroundImage(style.backgroundImage);
            const matrix = new DOMMatrix(style.transform);
            return { image, width: parseFloat(style.width), height: parseFloat(style.height),
                right: parseFloat(style.right), offsetY: parseFloat(style.top) - cell.getBoundingClientRect().height / 2,
                angle: Math.atan2(matrix.b, matrix.a), opacity: Number(style.opacity) };
        }));
        const comparisonQuoteLayer = element.querySelector(".compare-team-watermarks > span");
        const comparisonQuoteImage = comparisonQuoteLayer
            ? await loadBackgroundImage(getComputedStyle(comparisonQuoteLayer).backgroundImage) : null;
        // Render the watermark as a real, explicitly sized layer. html2canvas's
        // generated ::before node does not reliably fill an inset-sized header.
        const sourceQuote = element.querySelector(".candle-quote-team");
        const clonedQuote = clone.querySelector(".candle-quote-team");
        let watermarkImage = null;
        if (sourceQuote && clonedQuote) {
            const watermarkStyle = getComputedStyle(sourceQuote, "::before");
            watermarkImage = await loadBackgroundImage(watermarkStyle.backgroundImage);
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
        // html2canvas lays the detached copy out once more in its own document.
        // Its Korean font metrics can make the summary rows a little taller than
        // the browser-side measurement (most noticeably when no medal badge is
        // present). Give that final layout room instead of cropping it to the
        // first getBoundingClientRect() result. Transparent slack is removed by
        // trimTransparentCanvas after rendering.
        await nextPaint();
        const captureWidth = Math.ceil(Math.max(
            clone.scrollWidth,
            clone.offsetWidth,
            clone.getBoundingClientRect().width,
        ));
        const measuredHeight = Math.ceil(Math.max(
            clone.scrollHeight,
            clone.offsetHeight,
            clone.getBoundingClientRect().height,
            stage.scrollHeight,
            stage.offsetHeight,
            stage.getBoundingClientRect().height,
        ));
        const captureHeight = measuredHeight + 32;
        const renderedCanvas = await html2canvas(stage, {
            backgroundColor: null,
            logging: false,
            onclone: (clonedDocument) => {
                const comparisonLayer = clonedDocument.querySelector(".candle-export-clone .compare-team-watermarks > span");
                if (comparisonLayer && comparisonQuoteImage) {
                    const bounds = comparisonLayer.getBoundingClientRect();
                    const image = centeredCoverImage(comparisonQuoteImage, bounds.width, bounds.height);
                    comparisonLayer.style.backgroundImage = `url(${JSON.stringify(image)})`;
                    comparisonLayer.style.backgroundSize = "100% 100%";
                    comparisonLayer.style.backgroundPosition = "0px 0px";
                    comparisonLayer.style.backgroundRepeat = "no-repeat";
                }
                clonedDocument.querySelectorAll(".candle-export-clone .compare-player-cell").forEach((cell, index) => {
                    const logo = cellWatermarks[index];
                    if (!logo?.image) return;
                    const bounds = cell.getBoundingClientRect();
                    const canvas = document.createElement("canvas");
                    canvas.width = Math.ceil(bounds.width * 4);
                    canvas.height = Math.ceil(bounds.height * 4);
                    const context = canvas.getContext("2d");
                    context.scale(4, 4);
                    const tint = cell.style.getPropertyValue("--compare-team-tint").trim();
                    if (tint) {
                        context.fillStyle = tint;
                        context.fillRect(0, 0, bounds.width, bounds.height);
                    }
                    context.globalAlpha = logo.opacity;
                    context.translate(bounds.width - logo.right - logo.width / 2, bounds.height / 2 + logo.offsetY);
                    context.rotate(logo.angle);
                    const scale = Math.min(logo.width / logo.image.naturalWidth, logo.height / logo.image.naturalHeight);
                    const width = logo.image.naturalWidth * scale;
                    const height = logo.image.naturalHeight * scale;
                    context.drawImage(logo.image, -width / 2, -height / 2, width, height);
                    cell.style.backgroundImage = `url(${JSON.stringify(canvas.toDataURL("image/png"))})`;
                    cell.style.backgroundSize = "100% 100%";
                    cell.style.backgroundPosition = "0px 0px";
                    cell.style.backgroundRepeat = "no-repeat";
                });
                clonedDocument.querySelectorAll("img").forEach(image => {
                    image.style.setProperty("display", "inline-block", "important");
                });
                clonedDocument.querySelectorAll(".candle-export-avatar-image").forEach(avatar => {
                    avatar.style.width = `${avatar.parentElement.clientWidth}px`;
                    avatar.style.height = `${avatar.parentElement.clientHeight}px`;
                });
                clonedDocument.querySelectorAll(".candle-export-clone .candle-prediction-events > div").forEach(card => {
                    const accent = card.querySelector(".candle-prediction-card-accent");
                    if (accent) accent.style.height = `${card.getBoundingClientRect().height}px`;
                });
                const q = clonedDocument.querySelector('.candle-export-clone .candle-quote-team');
                const layer = q?.querySelector('.candle-export-team-watermark');
                if (layer) {
                    // windowWidth can switch responsive padding after cloning.
                    // Size the layer against this final capture layout.
                    const bounds = q.getBoundingClientRect();
                    layer.style.width = `${bounds.width}px`;
                    layer.style.height = `${bounds.height}px`;
                    if (watermarkImage) {
                        // Rasterize the centered cover crop against the FINAL
                        // bounds, avoiding html2canvas's SVG/background offsets.
                        const image = centeredCoverImage(watermarkImage, bounds.width, bounds.height);
                        layer.style.backgroundImage = `url(${JSON.stringify(image)})`;
                        layer.style.backgroundSize = "100% 100%";
                        layer.style.backgroundPosition = "0px 0px";
                        layer.style.backgroundRepeat = "no-repeat";
                    }
                }
            },
            scale: 4,
            useCORS: true,
            width: captureWidth,
            height: captureHeight,
            windowWidth: exportWidth,
        });
        const canvas = trimTransparentCanvas(renderedCanvas);
        const framedCanvas = frameRoundedCanvas(canvas, terminalRadius, terminalBorderColor, canvas.width / captureWidth);
        clickDownload(framedCanvas.toDataURL("image/png"), filename);
    } finally {
        renderStyle.remove();
        stage.remove();
    }
}
