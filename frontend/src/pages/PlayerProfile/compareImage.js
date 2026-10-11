// 비교 표를 좌우 스크롤 없이 전체 폭 그대로 PNG로 저장한다. 좁은 모바일에서도 표 전체가 한 장에 담긴다.
const PADDING = 12;

// html2canvas는 color-mix()가 계산된 color(srgb …) 형식을 읽지 못한다. 캔버스로 한 픽셀 칠해 rgba()로 바꾼다.
const colorProperties = ['color', 'background-color', 'background-image', 'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color', 'box-shadow', 'outline-color', 'text-decoration-color'];
function flattenColors(doc, root) {
    const canvas = doc.createElement('canvas');
    canvas.width = canvas.height = 1;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    const cache = new Map();
    const toRgba = color => {
        if (!cache.has(color)) {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = '#000';
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);
            const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data;
            cache.set(color, `rgba(${r}, ${g}, ${b}, ${+(a / 255).toFixed(3)})`);
        }
        return cache.get(color);
    };
    const view = doc.defaultView;
    [root, ...root.querySelectorAll('*')].forEach(element => {
        const style = view.getComputedStyle(element);
        for (const property of colorProperties) {
            const value = style.getPropertyValue(property);
            if (value.includes('color(')) element.style.setProperty(property, value.replace(/color\([^()]*\)/g, toRgba), 'important');
        }
    });
}

function prepareClone(doc, root, width) {

    root.style.overflow = 'visible';
    root.style.boxSizing = 'border-box';
    root.style.width = `${width}px`;
    root.style.padding = `${PADDING}px`;
    root.style.background = '#fff';
    root.style.backgroundImage = 'none';
    root.querySelectorAll('.compare-remove').forEach(node => node.remove());
    root.querySelectorAll('select').forEach(select => {
        const label = doc.createElement('span');
        label.className = 'compare-head-tag';
        label.textContent = select.selectedOptions[0]?.textContent || '';
        label.style.cssText = 'display:inline-block;line-height:1.5;';
        select.replaceWith(label);
    });
    root.querySelectorAll('.compare-head-photo > img:first-child').forEach(image => {
        // html2canvas는 object-fit을 적용하지 않고 늘려 그리므로 배경 이미지로 바꿔 그린다.
        const photo = doc.createElement('div');
        photo.className = image.className;
        photo.style.cssText = `display:block;width:100%;height:100%;border-radius:15px;background:#eef2f6 url("${image.currentSrc || image.src}") center top / cover no-repeat;`;
        image.replaceWith(photo);
    });
    root.querySelectorAll('.compare-head strong').forEach(name => { name.style.overflow = 'visible'; name.style.lineHeight = '1.4'; });
    root.querySelectorAll('th, td').forEach(cell => { cell.style.position = 'static'; cell.style.boxShadow = 'none'; cell.style.willChange = 'auto'; });
    root.querySelectorAll('.compare-group th > span').forEach(span => { span.style.position = 'static'; });
    flattenColors(doc, root);
    const table = root.querySelector('table');
    if (table) { table.style.width = `${width - PADDING * 2}px`; table.style.minWidth = '0'; }
}

export async function saveCompareImage(node, filename) {
    if (!node) return;
    const { default: html2canvas } = await import('html2canvas');
    // 화면에 보이는 표 폭 그대로 저장한다(선수가 적으면 좁게, 모바일에서는 스크롤로 가려진 부분까지 전체).
    const table = node.querySelector('table');
    const width = Math.ceil(table ? table.getBoundingClientRect().width : node.scrollWidth) + PADDING * 2;
    // html2canvas는 글자 기준선을 재려고 본문에 1x1 img를 넣는다. Tailwind base의 img { display: block }이 이를 줄바꿈시켜
    // 글자가 아래로 밀리므로, 렌더링하는 동안만 이 측정용 img를 inline-block으로 둔다.
    const fix = document.createElement('style');
    fix.textContent = 'img[width="1"][height="1"] { display: inline-block !important; }';
    document.head.appendChild(fix);
    let canvas;
    try { canvas = await html2canvas(node, {
        scale: Math.min(3, Math.max(2, window.devicePixelRatio || 2)),
        backgroundColor: '#ffffff',
        useCORS: true,
        width,
        windowWidth: Math.max(window.innerWidth, width),
        scrollX: 0,
        scrollY: 0,
        onclone: (doc, element) => prepareClone(doc, element, width),
    }); } finally { fix.remove(); }
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
    if (!blob) throw new Error('이미지를 만들지 못했습니다.');
    const file = new File([blob], filename, { type: 'image/png' });
    // 터치 기기에서는 공유 시트(사진에 저장)를 먼저 띄운다. 취소하면 다운로드로 넘어가지 않는다.
    if (window.matchMedia?.('(pointer: coarse)').matches && navigator.canShare?.({ files: [file] })) {
        try { await navigator.share({ files: [file] }); return; } catch (error) { if (error?.name === 'AbortError') return; }
    }
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
