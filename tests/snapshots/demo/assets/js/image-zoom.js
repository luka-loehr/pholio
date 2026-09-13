// image-zoom.js — image zoom: react-medium-image-zoom 5.4.9 (dist/controlled.js and
// dist/utils/*) with the settings from reference UI components/image-zoom.js
// (zoomMargin 20, wrapElement span, zoomImg.src = src of the image, zoomImg.sizes undefined).
//
// Static DOM (components/image-zoom.php): span[data-rmiz] > span[data-rmiz-content="not-found"] > img.
// Once the image is decoded, as in the original:
//   · data-rmiz-content="found" and next to it span[data-rmiz-ghost] with the zoom button
//     (position from offsetTop/Left/Width/Height, kept up to date via ResizeObserver),
//   · at the end of <body> div[data-rmiz-portal] with the <dialog> (overlay, content,
//     enlarged image, button to zoom out).
// States UNLOADED → LOADING → LOADED → UNLOADING → UNLOADED:
//   · zoom via click on the image or the button: body overflow hidden + fixed width,
//     showModal(), overlay "visible", transform to the window centre, LOADED after transitionend;
//     then the image carries the zoom source (src) without sizes.
//   · zoom out: Esc (capture), click on content or image, mouse wheel (without Ctrl,
//     without pinch zoom), swipe > 10 px, the dialog's close event; after transitionend
//     (at the latest duration + 50 ms) UNLOADED, scroll lock gone, dialog.close().
//   · window size changes while zoomed: transform recomputed without transition (shouldRefresh).
// SVG content (UNSAFE_handleSvg) and background images are not supported; the
// generator only emits <img>.

const IMAGE_QUERY = ['img', 'svg', '[role="img"]', '[data-zoom]'].map((x) => `${x}:not([aria-hidden="true"])`).join(',');
const ZOOM_MARGIN = 20;
const SWIPE_THRESHOLD = 10;
const LABEL_ZOOM = 'Expand image';
const LABEL_UNZOOM = 'Minimize image';
const ICON_ZOOM = '<svg aria-hidden="true" data-rmiz-btn-zoom-icon="true" fill="currentColor" focusable="false" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M 9 1 L 9 2 L 12.292969 2 L 2 12.292969 L 2 9 L 1 9 L 1 14 L 6 14 L 6 13 L 2.707031 13 L 13 2.707031 L 13 6 L 14 6 L 14 1 Z"></path></svg>';
const ICON_UNZOOM = '<svg aria-hidden="true" data-rmiz-btn-unzoom-icon="true" fill="currentColor" focusable="false" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M 14.144531 1.148438 L 9 6.292969 L 9 3 L 8 3 L 8 8 L 13 8 L 13 7 L 9.707031 7 L 14.855469 1.851563 Z M 8 8 L 3 8 L 3 9 L 6.292969 9 L 1.148438 14.144531 L 1.851563 14.855469 L 7 9.707031 L 7 13 L 8 13 Z"></path></svg>';

let booted = false;

export function boot(doc = document) {
  if (booted) return;
  booted = true;
  for (const wrap of doc.querySelectorAll('span[data-rmiz]')) {
    if (!wrap.__ndZoom) wrap.__ndZoom = new Zoom(wrap);
  }
}

// ---- Geometry (utils/get-scale.js, get-img-*-style.js, get-modal-img-transform.js) -----

function scaleToWindow(width, height, offset) {
  return Math.min((window.innerWidth - offset * 2) / width, (window.innerHeight - offset * 2) / height);
}

function getScale({ containerHeight, containerWidth, hasScalableSrc, offset, targetHeight, targetWidth }) {
  if (containerHeight === 0 || containerWidth === 0) return 1;
  if (!hasScalableSrc && targetHeight !== 0 && targetWidth !== 0) {
    const scale = scaleToWindow(targetWidth, targetHeight, offset);
    const ratio = targetWidth > targetHeight ? targetWidth / containerWidth : targetHeight / containerHeight;
    return scale > 1 ? ratio : scale * ratio;
  }
  return scaleToWindow(containerWidth, containerHeight, offset);
}

function parsePosition(position, relative) {
  const n = parseFloat(position);
  return position.endsWith('%') ? (relative * n) / 100 : n;
}

function positioned(base, visibleWidth, visibleHeight) {
  const [posLeft = '50%', posTop = '50%'] = base.position.split(' ');
  const x = parsePosition(posLeft, base.containerWidth - visibleWidth);
  const y = parsePosition(posTop, base.containerHeight - visibleHeight);
  const scale = getScale({ ...base, containerHeight: visibleHeight, containerWidth: visibleWidth });
  return {
    top: base.containerTop + y,
    left: base.containerLeft + x,
    width: visibleWidth * scale,
    height: visibleHeight * scale,
    initialTransform: `translate(0,0) scale(${1 / scale})`,
  };
}

function objectFitStyle(base, objectFit) {
  let fit = objectFit;
  if (fit === 'scale-down') {
    fit = base.targetWidth <= base.containerWidth && base.targetHeight <= base.containerHeight ? 'none' : 'contain';
  }
  if (fit === 'cover' || fit === 'contain') {
    const w = base.containerWidth / base.targetWidth;
    const h = base.containerHeight / base.targetHeight;
    const ratio = fit === 'cover' ? Math.max(w, h) : Math.min(w, h);
    return positioned(base, base.targetWidth * ratio, base.targetHeight * ratio);
  }
  if (fit === 'none') return positioned(base, base.targetWidth, base.targetHeight);
  const ratio = Math.max(base.containerWidth / base.targetWidth, base.containerHeight / base.targetHeight);
  const scale = getScale({ ...base, containerHeight: base.targetHeight * ratio, containerWidth: base.targetWidth * ratio });
  return {
    top: base.containerTop,
    left: base.containerLeft,
    width: base.containerWidth * scale,
    height: base.containerHeight * scale,
    initialTransform: `translate(0,0) scale(${1 / scale})`,
  };
}

function regularStyle(base) {
  const scale = getScale(base);
  return {
    top: base.containerTop,
    left: base.containerLeft,
    width: base.containerWidth * scale,
    height: base.containerHeight * scale,
    initialTransform: `translate(0,0) scale(${1 / scale})`,
  };
}

function modalTransform({ height, initialTransform, isZoomed, left, top, userTransform, width }) {
  let centered = '';
  if (userTransform !== 'none' && userTransform !== '') {
    centered = `translate(${width / 2}px,${height / 2}px) ${userTransform} translate(${-width / 2}px,${-height / 2}px)`;
  }
  if (!isZoomed) return centered === '' ? initialTransform : `${initialTransform} ${centered}`;
  const base = `translate(${window.innerWidth / 2 - (left + width / 2)}px,${window.innerHeight / 2 - (top + height / 2)}px) scale(1)`;
  return centered === '' ? base : `${base} ${centered}`;
}

// ---- Component ----------------------------------------------------------------

function portal() {
  const existing = document.querySelector('[data-rmiz-portal]');
  if (existing) return existing;
  const el = document.createElement('div');
  el.setAttribute('data-rmiz-portal', '');
  document.body.appendChild(el);
  return el;
}

class Zoom {
  constructor(wrap) {
    this.wrap = wrap;
    this.content = wrap.querySelector(':scope > [data-rmiz-content]');
    this.img = this.content?.querySelector(IMAGE_QUERY) ?? null;
    this.state = 'UNLOADED';
    this.loadedImg = null;
    this.zoomImgLoaded = false;
    this.shouldRefresh = false;
    this.isScaling = false;
    this.touchYStart = undefined;
    this.prevBody = { overflow: '', width: '' };
    const gen4 = () => Math.random().toString(16).slice(-4);
    this.id = gen4() + gen4() + gen4();
    this.zoomSrc = this.img?.getAttribute('src') ?? '';
    this.ghost = null;
    this.dialog = null;

    if (!this.img || this.img.tagName !== 'IMG') return;
    this.onImgLoad = () => this.handleImgLoad();
    this.img.addEventListener('load', this.onImgLoad);
    this.img.addEventListener('click', () => this.zoom());
    new ResizeObserver(() => this.render()).observe(this.img);
    this.handleImgLoad();

    this.onKeyDown = (e) => {
      if (e.key !== 'Escape') return;
      e.preventDefault();
      e.stopPropagation();
      this.unzoom();
    };
    this.onWheel = (e) => {
      if (e.ctrlKey || this.isScaling) return;
      if ((window.visualViewport?.scale ?? 1) > 1) return;
      e.stopPropagation();
      queueMicrotask(() => this.unzoom());
    };
    this.onResize = () => {
      this.shouldRefresh = true;
      this.render();
    };
    this.onTouchStart = (e) => {
      if (e.touches.length > 1) {
        this.isScaling = true;
        return;
      }
      if (e.changedTouches.length === 1) this.touchYStart = e.changedTouches[0].screenY;
    };
    this.onTouchMove = (e) => {
      const touch = e.changedTouches[0];
      if (this.isScaling || (window.visualViewport?.scale ?? 1) > 1 || this.touchYStart == null || !touch) return;
      if (Math.abs(touch.screenY - this.touchYStart) > SWIPE_THRESHOLD) {
        this.touchYStart = undefined;
        this.unzoom();
      }
    };
    this.onTouchEnd = () => {
      this.touchYStart = undefined;
    };
  }

  hasImage() {
    return this.img !== null && this.loadedImg !== null && getComputedStyle(this.img).display !== 'none';
  }

  handleImgLoad() {
    const src = this.img.currentSrc;
    if (!src) return;
    const img = new Image();
    img.sizes = this.img.sizes;
    img.srcset = this.img.srcset;
    if (this.img.crossOrigin) img.crossOrigin = this.img.crossOrigin;
    img.src = src;
    const loaded = () => {
      this.loadedImg = img;
      this.render();
    };
    img.decode().then(loaded).catch(() => {
      if (img.complete && img.naturalHeight !== 0) loaded();
      else img.onload = loaded;
    });
  }

  zoom() {
    if (!this.hasImage() || this.state === 'LOADING' || this.state === 'LOADED') return;
    const body = document.body.style;
    this.prevBody = { overflow: body.overflow, width: body.width };
    const width = document.body.clientWidth;
    body.overflow = 'hidden';
    body.width = `${width}px`;
    this.dialog.showModal();
    // One layout between showModal and the new transform, so the transition runs.
    void this.dialog.offsetWidth;
    this.setState('LOADING');
  }

  unzoom() {
    if (this.state !== 'LOADING' && this.state !== 'LOADED') return;
    this.setState('UNLOADING');
  }

  setState(next) {
    const prev = this.state;
    this.state = next;
    this.render();
    if (prev !== 'LOADING' && next === 'LOADING') {
      this.isScaling = false;
      this.loadZoomImg();
      window.addEventListener('resize', this.onResize, { passive: true });
      window.addEventListener('touchstart', this.onTouchStart, { passive: true });
      window.addEventListener('touchmove', this.onTouchMove, { passive: true });
      window.addEventListener('touchend', this.onTouchEnd, { passive: true });
      window.addEventListener('touchcancel', this.onTouchEnd, { passive: true });
      document.addEventListener('keydown', this.onKeyDown, true);
    } else if (prev !== 'LOADED' && next === 'LOADED') {
      window.addEventListener('wheel', this.onWheel, { passive: true });
    } else if (prev !== 'UNLOADING' && next === 'UNLOADING') {
      this.ensureTransitionEnd();
      window.removeEventListener('wheel', this.onWheel);
      window.removeEventListener('touchstart', this.onTouchStart);
      window.removeEventListener('touchmove', this.onTouchMove);
      window.removeEventListener('touchend', this.onTouchEnd);
      window.removeEventListener('touchcancel', this.onTouchEnd);
      document.removeEventListener('keydown', this.onKeyDown, true);
    } else if (prev !== 'UNLOADED' && next === 'UNLOADED') {
      const body = document.body.style;
      body.width = this.prevBody.width;
      body.overflow = this.prevBody.overflow;
      window.removeEventListener('resize', this.onResize);
      this.dialog?.close();
    }
  }

  loadZoomImg() {
    if (!this.zoomSrc) return;
    const img = new Image();
    img.sizes = '';
    img.srcset = '';
    img.src = this.zoomSrc;
    const loaded = () => {
      this.zoomImgLoaded = true;
      this.render();
    };
    img.decode().then(loaded).catch(() => {
      if (img.complete && img.naturalHeight !== 0) loaded();
      else img.onload = loaded;
    });
  }

  handleTransitionEnd() {
    clearTimeout(this.timeout);
    if (this.state === 'LOADING') this.setState('LOADED');
    else if (this.state === 'UNLOADING') {
      this.shouldRefresh = false;
      this.setState('UNLOADED');
    }
  }

  ensureTransitionEnd() {
    const img = this.dialog?.querySelector('[data-rmiz-modal-img]');
    if (!img) return;
    const td = getComputedStyle(img).transitionDuration;
    const value = parseFloat(td);
    if (value !== 0 && !Number.isNaN(value)) {
      const ms = value * (td.endsWith('ms') ? 1 : 1000) + 50;
      this.timeout = setTimeout(() => this.handleTransitionEnd(), ms);
    }
  }

  modalStyle(isZoomed) {
    const img = this.img;
    const rect = img.getBoundingClientRect();
    const computed = getComputedStyle(img);
    const base = {
      containerHeight: rect.height,
      containerLeft: rect.left,
      containerTop: rect.top,
      containerWidth: rect.width,
      // zoomImg.src is always set → scalable source.
      hasScalableSrc: true,
      offset: ZOOM_MARGIN,
      position: computed.objectPosition,
      targetHeight: this.loadedImg?.naturalHeight || rect.height,
      targetWidth: this.loadedImg?.naturalWidth || rect.width,
    };
    const position = this.loadedImg ? objectFitStyle(base, computed.objectFit) : regularStyle(base);
    const style = {
      top: position.top,
      left: position.left,
      width: position.width,
      height: position.height,
      transform: modalTransform({ ...position, isZoomed, userTransform: computed.transform }),
    };
    if (isZoomed && this.shouldRefresh) style.transitionDuration = '0.01ms';
    return style;
  }

  render() {
    const hasImage = this.hasImage();
    const active = this.state === 'LOADING' || this.state === 'LOADED';

    this.content.setAttribute('data-rmiz-content', hasImage ? 'found' : 'not-found');
    this.content.style.visibility = this.state === 'UNLOADED' ? 'visible' : 'hidden';
    if (!hasImage) return;

    if (!this.ghost) {
      this.ghost = document.createElement('span');
      this.ghost.setAttribute('data-rmiz-ghost', '');
      const button = document.createElement('button');
      const alt = this.img.alt;
      button.setAttribute('aria-label', alt ? `${LABEL_ZOOM}: ${alt}` : LABEL_ZOOM);
      button.setAttribute('data-rmiz-btn-zoom', '');
      button.setAttribute('type', 'button');
      button.innerHTML = ICON_ZOOM;
      button.addEventListener('click', () => this.zoom());
      this.ghost.appendChild(button);
      this.wrap.appendChild(this.ghost);
    }
    this.ghost.style.height = `${this.img.offsetHeight}px`;
    this.ghost.style.left = `${this.img.offsetLeft}px`;
    this.ghost.style.width = `${this.img.offsetWidth}px`;
    this.ghost.style.top = `${this.img.offsetTop}px`;

    if (!this.dialog) this.createDialog();
    this.dialog.querySelector('[data-rmiz-modal-overlay]').setAttribute(
      'data-rmiz-modal-overlay',
      this.state === 'UNLOADED' || this.state === 'UNLOADING' ? 'hidden' : 'visible',
    );

    const modalImg = this.modalImg;
    const style = this.modalStyle(active);
    const useZoomImg = this.zoomImgLoaded && this.state === 'LOADED';
    modalImg.setAttribute('alt', this.img.alt);
    if (this.img.crossOrigin) modalImg.setAttribute('crossorigin', this.img.crossOrigin);
    if (!useZoomImg && this.img.sizes) modalImg.setAttribute('sizes', this.img.sizes);
    else modalImg.removeAttribute('sizes');
    modalImg.setAttribute('src', useZoomImg ? this.zoomSrc : this.img.currentSrc);
    if (this.img.srcset) modalImg.setAttribute('srcset', this.img.srcset);
    modalImg.setAttribute('height', String(style.height));
    modalImg.setAttribute('width', String(style.width));
    modalImg.style.top = `${style.top}px`;
    modalImg.style.left = `${style.left}px`;
    modalImg.style.width = `${style.width}px`;
    modalImg.style.height = `${style.height}px`;
    modalImg.style.transform = style.transform;
    if (style.transitionDuration) modalImg.style.transitionDuration = style.transitionDuration;
    else modalImg.style.removeProperty('transition-duration');
  }

  createDialog() {
    const dialog = document.createElement('dialog');
    dialog.setAttribute('aria-labelledby', `rmiz-modal-img-${this.id}`);
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('data-rmiz-modal', '');
    dialog.setAttribute('id', `rmiz-modal-${this.id}`);
    dialog.setAttribute('role', 'dialog');

    const overlay = document.createElement('div');
    overlay.setAttribute('data-rmiz-modal-overlay', 'hidden');
    const content = document.createElement('div');
    content.setAttribute('data-rmiz-modal-content', '');

    const img = document.createElement('img');
    img.setAttribute('data-rmiz-modal-img', '');
    img.setAttribute('id', `rmiz-modal-img-${this.id}`);
    img.addEventListener('transitionend', () => this.handleTransitionEnd());

    const unzoom = document.createElement('button');
    unzoom.setAttribute('aria-label', LABEL_UNZOOM);
    unzoom.setAttribute('data-rmiz-btn-unzoom', '');
    unzoom.setAttribute('type', 'button');
    unzoom.innerHTML = ICON_UNZOOM;
    unzoom.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      this.unzoom();
    });

    content.append(img, unzoom);
    dialog.append(overlay, content);
    dialog.addEventListener('click', (e) => {
      if (e.target === content || e.target === img) {
        e.stopPropagation();
        this.unzoom();
      }
    });
    dialog.addEventListener('close', (e) => {
      e.stopPropagation();
      this.unzoom();
    });
    dialog.addEventListener('cancel', (e) => e.preventDefault());

    portal().appendChild(dialog);
    this.dialog = dialog;
    this.modalImg = img;
  }
}
