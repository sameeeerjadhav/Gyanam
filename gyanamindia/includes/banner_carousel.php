<?php
/**
 * Banner Carousel Partial
 * Supports: images (jpg/png/webp/gif), videos (mp4/webm)
 *           horizontal and vertical orientations
 * Include after $activeBanners is populated.
 * Set $imgPrefix to relative path e.g. '../uploads/announcements/'
 */
$imgPrefix     = $imgPrefix ?? '../uploads/announcements/';
$carouselCount = count($activeBanners);

$videoExts = ['mp4', 'webm', 'ogg'];

function _bannerIsVideo($path, $videoExts) {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $videoExts);
}
function _bannerIsVertical($b) {
    return isset($b['orientation']) && $b['orientation'] === 'vertical';
}
?>
<?php if (!empty($activeBanners)): ?>
<style>
/* ── Banner Carousel (full image visible — no crop/zoom) ── */
.banner-carousel-wrap {
    position: relative;
    width: 100%;
    border-radius: var(--radius-xl, 16px);
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    background: #0f172a;
    user-select: none;
}
.carousel-track {
    display: flex;
    transition: transform 0.55s cubic-bezier(0.4, 0, 0.2, 1);
    will-change: transform;
}
.carousel-slide {
    min-width: 100%;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #0f172a;
    min-height: 180px;
    max-height: 360px;
}

/* ── Horizontal image slide — show FULL banner ── */
.carousel-slide img.slide-media {
    width: 100%;
    height: auto;
    max-height: 360px;
    display: block;
    object-fit: contain;
    object-position: center;
    transform: none !important;
    transition: none;
}

/* ── Vertical image slide — blurred background trick ── */
.carousel-slide.vertical-slide {
    height: 360px;
    max-height: 360px;
}
.vertical-blur-bg {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    filter: blur(22px) brightness(0.55) saturate(1.2);
    transform: scale(1.08);
    z-index: 0;
}
.carousel-slide.vertical-slide img.slide-media {
    position: relative;
    z-index: 1;
    width: auto;
    max-width: 100%;
    height: 100%;
    max-height: 360px;
    object-fit: contain;
    display: block;
    border-radius: 0;
    box-shadow: 0 4px 30px rgba(0,0,0,0.5);
}

/* ── Video slide ── */
.carousel-slide video.slide-media {
    width: 100%;
    height: auto;
    max-height: 360px;
    display: block;
    object-fit: contain;
    background: #0f172a;
}
.carousel-slide.vertical-slide video.slide-media {
    width: auto;
    height: 360px;
    object-fit: contain;
    position: relative;
    z-index: 1;
}

/* ── Caption overlay (compact, doesn’t cover artwork) ── */
.carousel-slide-caption {
    position: absolute;
    left: 12px;
    bottom: 12px;
    right: auto;
    max-width: min(70%, 420px);
    padding: .35rem .7rem;
    border-radius: 8px;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(6px);
    color: #fff;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    text-shadow: none;
    z-index: 5;
    pointer-events: none;
}
@media (max-width: 640px) {
    .carousel-slide,
    .carousel-slide.vertical-slide {
        min-height: 140px;
        max-height: 220px;
        height: auto;
    }
    .carousel-slide img.slide-media,
    .carousel-slide video.slide-media,
    .carousel-slide.vertical-slide img.slide-media,
    .carousel-slide.vertical-slide video.slide-media {
        max-height: 220px;
    }
    .carousel-slide.vertical-slide video.slide-media { height: 220px; }
}

/* ── Dots ── */
.carousel-dots {
    position: absolute;
    bottom: 14px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 7px;
    z-index: 20;
}
.carousel-dot {
    width: 8px; height: 8px;
    border-radius: 99px;
    background: rgba(255,255,255,0.45);
    border: none;
    cursor: pointer;
    padding: 0;
    transition: all 0.35s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}
.carousel-dot.active { background: #fff; width: 26px; }

/* ── Arrows ── */
.carousel-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 38px; height: 38px;
    border-radius: 50%;
    background: rgba(255,255,255,0.88);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #1e293b;
    z-index: 20;
    opacity: 0;
    transition: opacity 0.25s, background 0.2s;
    box-shadow: 0 3px 10px rgba(0,0,0,0.18);
}
.banner-carousel-wrap:hover .carousel-arrow { opacity: 1; }
.carousel-arrow:hover { background: #fff; }
.carousel-arrow.prev { left: 14px; }
.carousel-arrow.next { right: 14px; }

/* ── Progress bar ── */
.carousel-progress {
    position: absolute;
    bottom: 0; left: 0;
    height: 3px;
    background: rgba(255,255,255,0.85);
    z-index: 20;
    width: 0%;
    transition: none;
}
.carousel-progress.animating {
    transition: width 5s linear;
    width: 100%;
}

/* ── Video indicator badge ── */
.video-badge {
    position: absolute;
    top: 10px; right: 10px;
    background: rgba(0,0,0,0.6);
    color: #fff;
    border-radius: 20px;
    font-size: .62rem;
    font-weight: 800;
    padding: .2rem .6rem;
    display: flex;
    align-items: center;
    gap: .3rem;
    z-index: 10;
    backdrop-filter: blur(4px);
}
.video-badge svg { width: 10px; height: 10px; }
</style>

<div class="banner-carousel-wrap" id="bannerCarousel">
    <div class="carousel-track" id="carouselTrack">
        <?php foreach($activeBanners as $i => $b):
            $isVideo    = _bannerIsVideo($b['image_path'], $videoExts);
            $isVertical = _bannerIsVertical($b);
            $mediaSrc   = $imgPrefix . htmlspecialchars($b['image_path']);
            $slideClass = 'carousel-slide' . ($i === 0 ? ' is-active' : '') . ($isVertical ? ' vertical-slide' : '');
        ?>
        <div class="<?= $slideClass ?>" data-index="<?= $i ?>" data-is-video="<?= $isVideo ? '1' : '0' ?>">

            <?php if ($isVertical && !$isVideo): ?>
            <!-- Blurred background for vertical images -->
            <div class="vertical-blur-bg" style="background-image:url('<?= $mediaSrc ?>')"></div>
            <?php endif; ?>

            <?php if ($isVideo): ?>
            <video class="slide-media"
                   src="<?= $mediaSrc ?>"
                   <?= $i === 0 ? 'autoplay' : '' ?>
                   muted
                   playsinline
                   preload="<?= $i === 0 ? 'auto' : 'none' ?>">
            </video>
            <div class="video-badge">
                <svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                VIDEO
            </div>
            <?php else: ?>
            <img class="slide-media"
                 src="<?= $mediaSrc ?>"
                 alt="<?= htmlspecialchars($b['title']) ?>"
                 loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
            <?php endif; ?>

            <div class="carousel-slide-caption"><?= htmlspecialchars($b['title']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($carouselCount > 1): ?>
    <div class="carousel-dots" id="carouselDots">
        <?php for ($i = 0; $i < $carouselCount; $i++): ?>
        <button class="carousel-dot <?= $i === 0 ? 'active' : '' ?>" data-idx="<?= $i ?>" aria-label="Go to slide <?= $i + 1 ?>"></button>
        <?php endfor; ?>
    </div>
    <button class="carousel-arrow prev" id="carouselPrev" aria-label="Previous">&#8249;</button>
    <button class="carousel-arrow next" id="carouselNext" aria-label="Next">&#8250;</button>
    <div class="carousel-progress" id="carouselProgress"></div>
    <?php endif; ?>
</div>

<script>
(function () {
    var wrap     = document.getElementById('bannerCarousel');
    var track    = document.getElementById('carouselTrack');
    var dots     = document.querySelectorAll('#carouselDots .carousel-dot');
    var slides   = document.querySelectorAll('#carouselTrack .carousel-slide');
    var prevBtn  = document.getElementById('carouselPrev');
    var nextBtn  = document.getElementById('carouselNext');
    var progress = document.getElementById('carouselProgress');
    var total    = slides.length;
    var current  = 0;
    var timer    = null;
    var INTERVAL = 5000;

    function getVideo(slideEl) {
        return slideEl.querySelector('video');
    }

    function stopProgress() {
        if (progress) { progress.classList.remove('animating'); progress.style.width = '0%'; }
    }

    function startProgress() {
        if (progress) {
            progress.classList.remove('animating');
            progress.style.width = '0%';
            void progress.offsetWidth; // reflow
            progress.classList.add('animating');
        }
    }

    function goTo(idx) {
        // Pause any playing video on current slide
        var curVid = getVideo(slides[current]);
        if (curVid) { curVid.pause(); curVid.currentTime = 0; }

        slides[current].classList.remove('is-active');
        current = (idx + total) % total;
        track.style.transform = 'translateX(-' + (current * 100) + '%)';
        slides[current].classList.add('is-active');
        dots.forEach(function(d, i) { d.classList.toggle('active', i === current); });

        // Handle video auto-play on new slide
        var newVid = getVideo(slides[current]);
        if (newVid) {
            stopProgress(); // no progress bar on video slides
            newVid.currentTime = 0;
            newVid.play().catch(function(){});
        } else {
            startProgress();
            startAuto();
        }
    }

    function startAuto() {
        clearInterval(timer);
        var vid = getVideo(slides[current]);
        if (!vid) { // only auto-advance for image slides
            timer = setInterval(function () { goTo(current + 1); }, INTERVAL);
        }
    }

    // When a video ENDS → advance to next slide automatically
    slides.forEach(function(slide) {
        var vid = getVideo(slide);
        if (vid) {
            vid.addEventListener('ended', function() { goTo(current + 1); });
        }
    });

    // Dot clicks
    dots.forEach(function (d) {
        d.addEventListener('click', function () {
            clearInterval(timer);
            goTo(+this.dataset.idx);
        });
    });

    // Arrows
    if (prevBtn) prevBtn.addEventListener('click', function () { clearInterval(timer); goTo(current - 1); startAuto(); });
    if (nextBtn) nextBtn.addEventListener('click', function () { clearInterval(timer); goTo(current + 1); startAuto(); });

    // Touch / swipe
    var touchStartX = 0;
    wrap.addEventListener('touchstart', function (e) { touchStartX = e.changedTouches[0].screenX; }, { passive: true });
    wrap.addEventListener('touchend', function (e) {
        var diff = touchStartX - e.changedTouches[0].screenX;
        if (Math.abs(diff) > 40) { clearInterval(timer); goTo(diff > 0 ? current + 1 : current - 1); startAuto(); }
    }, { passive: true });

    // Pause on hover (images only)
    wrap.addEventListener('mouseenter', function () {
        clearInterval(timer);
        if (progress && !getVideo(slides[current])) progress.classList.remove('animating');
    });
    wrap.addEventListener('mouseleave', function () { startAuto(); });

    // Init
    goTo(0);
    if (total > 1) startAuto();
})();
</script>
<?php endif; ?>
