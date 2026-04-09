/**
 * Spotify Personal Site — app.js
 *
 * Fetches data from PHP API endpoints and renders all page sections.
 * No framework; vanilla JS with Chart.js for charts.
 */

document.addEventListener('DOMContentLoaded', () => {
    // Kick off all data fetches in parallel
    const fetches = {
        profile:     fetchJSON('api/profile.php'),
        artists:     fetchJSON('api/top-artists.php'),
        tracks:      fetchJSON('api/top-tracks.php'),
        obsession:   fetchJSON('api/current-obsession.php'),
        recent:      fetchJSON('api/recently-played.php'),
        favorites:   fetchJSON('api/favorites.php'),
        lastUpdated: fetchJSON('api/last-updated.php'),
    };

    // Render each section independently — a failure in one doesn't block others
    fetches.profile.then(renderProfile).catch(err => showError('avatar', err));
    fetches.artists.then(renderTopArtists).catch(err => showError('top-artists-content', err));
    fetches.tracks.then(renderTopTracks).catch(err => showError('top-tracks-content', err));
    fetches.obsession.then(renderCurrentObsession).catch(err => showError('current-obsession-content', err));
    fetches.recent.then(renderRecentlyPlayed).catch(err => {
        showError('last-album-content', err);
        showError('last-track-content', err);
    });
    fetches.favorites.then(renderFavorites).catch(err => {
        showError('favorites-artists-content', err);
        showError('favorites-years-content', err);
    });
    fetches.lastUpdated.then(renderLastUpdated).catch(() => {});

    // Start Now Playing poll
    pollNowPlaying();
    setInterval(pollNowPlaying, 30000);
});

// -------------------------------------------------------------------
// Utilities
// -------------------------------------------------------------------

function fetchJSON(url) {
    return fetch(url).then(r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
    });
}

function showError(containerId, err) {
    const el = document.getElementById(containerId);
    if (el) el.innerHTML = `<span class="error-text">Unavailable</span>`;
    console.error(`Error in ${containerId}:`, err);
}

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (k === 'text') {
            node.textContent = v;
        } else if (k === 'html') {
            node.innerHTML = v;
        } else if (k === 'className') {
            node.className = v;
        } else if (k === 'style' && typeof v === 'object') {
            Object.assign(node.style, v);
        } else {
            node.setAttribute(k, v);
        }
    }
    for (const child of children) {
        if (typeof child === 'string') {
            node.appendChild(document.createTextNode(child));
        } else if (child) {
            node.appendChild(child);
        }
    }
    return node;
}

function link(text, href, className) {
    return el('a', { text, href, target: '_blank', rel: 'noopener', className: className || '' });
}

// -------------------------------------------------------------------
// Section Renderers
// -------------------------------------------------------------------

function renderProfile(data) {
    const avatar = document.getElementById('avatar');
    if (data.avatar_url) {
        avatar.innerHTML = '';
        avatar.appendChild(el('img', { src: data.avatar_url, alt: 'Profile' }));
    }
}

function renderCurrentObsession(data) {
    renderAlbumCard(data, 'In heavy rotation', 'current-obsession-content');
}

function renderRecentlyPlayed(data) {
    if (data.last_album) {
        renderAlbumCard(data.last_album, 'Last full listen', 'last-album-content');
    } else {
        const container = document.getElementById('last-album-content');
        container.innerHTML = '';
        container.appendChild(el('span', { className: 'shufflin-fallback', text: "Been shufflin'." }));
    }

    if (data.last_track) {
        renderAlbumCard(data.last_track, 'Last track', 'last-track-content');
    }
}

/**
 * Shared album card renderer for Sections 3, 6, and 7.
 *
 * Expects data with keys: album_art|art, album_name|name, album_url|spotify_url,
 * artist_name, artist_url. When data has both `name` (track) and `album_name`,
 * a track name line is rendered above the album name.
 */
function renderAlbumCard(data, label, containerId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    const artUrl    = data.album_art || data.art || '';
    const albumName = data.album_name || data.name || '';
    const albumUrl  = data.album_url || data.spotify_url || '';
    const artistName = data.artist_name || '';
    const artistUrl  = data.artist_url || '';

    // Present when this card represents a track rather than a bare album.
    const trackName = (data.name && data.album_name) ? data.name : null;
    const trackUrl  = trackName ? (data.spotify_url || '') : '';

    const artDiv = el('div', { className: 'album-card-art' });
    if (artUrl) {
        artDiv.appendChild(el('img', { src: artUrl, alt: albumName }));
    } else {
        artDiv.classList.add('album-card-art-placeholder');
    }

    const infoChildren = [
        el('div', { className: 'album-card-label', text: label }),
    ];
    if (trackName) {
        infoChildren.push(el('div', { className: 'album-card-track' }, [
            trackUrl ? link(trackName, trackUrl) : document.createTextNode(trackName)
        ]));
    }
    infoChildren.push(
        el('div', { className: 'album-card-album' }, [
            albumUrl ? link(albumName, albumUrl) : document.createTextNode(albumName)
        ]),
        el('div', { className: 'album-card-artist' }, [
            artistUrl ? link(artistName, artistUrl) : document.createTextNode(artistName)
        ]),
    );

    const infoDiv = el('div', { className: 'album-card-info' }, infoChildren);

    const card = el('div', { className: 'album-card' }, [artDiv, infoDiv]);
    container.appendChild(card);
}

function renderTopArtists(data) {
    const container = document.getElementById('top-artists-content');
    container.innerHTML = '';

    const grid = el('div', { className: 'top-artists-grid' });

    for (const artist of (data.artists || [])) {
        const imgDiv = el('div', { className: 'top-artist-img' });
        if (artist.image_url) {
            imgDiv.appendChild(el('img', { src: artist.image_url, alt: artist.name }));
        } else {
            imgDiv.appendChild(el('span', {
                className: 'artist-initial',
                text: (artist.name || '?')[0]
            }));
        }

        const nameDiv = el('div', { className: 'top-artist-name' }, [
            el('span', { className: 'rank-dot' }),
            link(artist.name, artist.spotify_url || '#'),
        ]);

        grid.appendChild(el('div', { className: 'top-artist-item' }, [imgDiv, nameDiv]));
    }

    container.appendChild(grid);
}

function renderTopTracks(data) {
    const container = document.getElementById('top-tracks-content');
    container.innerHTML = '';

    const list = el('div', { className: 'top-tracks-list' });

    (data.tracks || []).forEach((track, i) => {
        const rank = String(i + 1).padStart(2, '0');

        const row = el('div', { className: 'top-track-row' }, [
            el('div', { className: 'track-rank', text: rank }),
            el('div', { className: 'track-info' }, [
                el('div', { className: 'track-name' }, [
                    link(track.name, track.spotify_url || '#'),
                ]),
                el('div', { className: 'track-artist' }, [
                    link(track.artist_name, track.artist_url || '#'),
                ]),
            ]),
        ]);

        list.appendChild(row);
    });

    container.appendChild(list);
}

function renderLastUpdated(data) {
    if (!data.updated_at) return;
    const date = new Date(data.updated_at * 1000);
    const formatted = date.toLocaleString(undefined, {
        year: 'numeric', month: 'long', day: 'numeric',
        hour: 'numeric', minute: '2-digit',
    });
    document.getElementById('last-updated-label').textContent = `Data last refreshed: ${formatted}`;
}

// -------------------------------------------------------------------
// Now Playing (polled)
// -------------------------------------------------------------------

function pollNowPlaying() {
    fetchJSON('api/now-playing.php')
        .then(data => {
            const eq        = document.getElementById('equalizer');
            const marquee   = document.getElementById('marquee');
            const nothing   = document.getElementById('nothing-playing');
            const linkRow   = document.getElementById('now-playing-link-row');
            const linkEl    = document.getElementById('now-playing-link');

            if (!data.playing) {
                eq.style.display      = 'none';
                marquee.style.display = 'none';
                nothing.style.display = '';
                linkRow.style.display = 'none';
                return;
            }

            const content = `${data.track} <span class="marquee-sep">·</span> ${data.artist} <span class="marquee-sep">·</span> ${data.album}`;

            document.getElementById('marquee-text-1').innerHTML = content;
            document.getElementById('marquee-text-2').innerHTML = content;

            eq.style.display      = 'flex';
            marquee.style.display = 'flex';
            nothing.style.display = 'none';
            linkRow.style.display = '';
            linkEl.href = data.spotify_url || '#';
        })
        .catch(() => {
            // Silently fail — leave current state
        });
}

// -------------------------------------------------------------------
// Favorites Charts
// -------------------------------------------------------------------

/** Global references for chart cleanup on re-render */
let artistsChart = null;
let yearsChart = null;

function renderFavorites(data) {
    renderArtistsChart(data.top_artists || []);
    renderYearsChart(data.by_year || []);
}

function renderArtistsChart(topArtists) {
    const container = document.getElementById('favorites-artists-content');
    container.innerHTML = '';

    const wrapper = el('div', { className: 'chart-wrapper' });
    const chartContainer = el('div', { id: 'favorites-artists-chart-container' });
    const canvas = el('canvas');
    chartContainer.appendChild(canvas);
    wrapper.appendChild(chartContainer);
    container.appendChild(wrapper);

    const labels = topArtists.map(a => a.name);
    const counts = topArtists.map(a => a.count);
    const baseColors = labels.map(() => '#1DB954');

    const tooltip = document.getElementById('artist-tooltip');

    artistsChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: counts,
                backgroundColor: [...baseColors],
                borderWidth: 0,
                borderRadius: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
            },
            scales: {
                x: {
                    ticks: {
                        maxRotation: 45,
                        autoSkip: false,
                        font: { family: 'IBM Plex Mono', size: 10 },
                        color: '#5A5548',
                    },
                    grid: { color: '#1C1A14' },
                    border: { color: '#2A2820' },
                },
                y: {
                    title: {
                        display: true,
                        text: 'tracks',
                        font: { family: 'IBM Plex Mono', size: 10 },
                        color: '#5A5548',
                    },
                    ticks: {
                        font: { family: 'IBM Plex Mono', size: 10 },
                        color: '#5A5548',
                    },
                    grid: { color: '#1C1A14' },
                    border: { color: '#2A2820' },
                },
            },
            onHover: (event, elements) => {
                const idx = elements.length ? elements[0].index : -1;
                const colors = labels.map((_, i) => i === idx ? '#24FF6F' : '#1DB954');
                artistsChart.data.datasets[0].backgroundColor = colors;
                artistsChart.update('none');

                if (idx >= 0) {
                    document.getElementById('tooltip-name').textContent = labels[idx];
                    document.getElementById('tooltip-count').textContent = `${counts[idx]} tracks`;
                    tooltip.style.display = 'block';
                    tooltip.style.left = (event.native.clientX + 12) + 'px';
                    tooltip.style.top = (event.native.clientY - 10) + 'px';
                } else {
                    tooltip.style.display = 'none';
                }
            },
        },
    });

    // Hide tooltip on mouse leave
    canvas.addEventListener('mouseleave', () => {
        tooltip.style.display = 'none';
        artistsChart.data.datasets[0].backgroundColor = [...baseColors];
        artistsChart.update('none');
    });

    // Click handler — open lightbox
    canvas.addEventListener('click', (e) => {
        const elements = artistsChart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, true);
        if (elements.length) {
            openArtistLightbox(topArtists[elements[0].index]);
        }
    });

    // Pointer cursor on hoverable bars
    canvas.style.cursor = 'default';
    canvas.addEventListener('mousemove', (e) => {
        const elements = artistsChart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, true);
        canvas.style.cursor = elements.length ? 'pointer' : 'default';
    });
}

function renderYearsChart(byYear) {
    const container = document.getElementById('favorites-years-content');
    container.innerHTML = '';

    const wrapper = el('div', { className: 'chart-wrapper' });
    const chartContainer = el('div', { id: 'favorites-years-chart-container' });
    const canvas = el('canvas');
    chartContainer.appendChild(canvas);
    wrapper.appendChild(chartContainer);
    container.appendChild(wrapper);

    const labels = byYear.map(y => String(y.year));
    const counts = byYear.map(y => y.count);
    const baseColors = labels.map(() => '#1DB954');

    yearsChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: counts,
                backgroundColor: [...baseColors],
                borderWidth: 0,
                borderRadius: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1C1A14',
                    borderColor: '#3A3628',
                    borderWidth: 1,
                    titleColor: '#EDE8DC',
                    bodyColor: '#1DB954',
                    bodyFont: { family: 'IBM Plex Mono', size: 11 },
                    titleFont: { family: 'IBM Plex Sans', size: 12 },
                },
            },
            scales: {
                x: {
                    ticks: {
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 14,
                        font: { family: 'IBM Plex Mono', size: 10 },
                        color: '#5A5548',
                    },
                    grid: { color: '#1C1A14' },
                    border: { color: '#2A2820' },
                },
                y: {
                    title: {
                        display: true,
                        text: 'tracks',
                        font: { family: 'IBM Plex Mono', size: 10 },
                        color: '#5A5548',
                    },
                    ticks: {
                        font: { family: 'IBM Plex Mono', size: 10 },
                        color: '#5A5548',
                    },
                    grid: { color: '#1C1A14' },
                    border: { color: '#2A2820' },
                },
            },
            onHover: (event, elements) => {
                const idx = elements.length ? elements[0].index : -1;
                const colors = labels.map((_, i) => i === idx ? '#24FF6F' : '#1DB954');
                yearsChart.data.datasets[0].backgroundColor = colors;
                yearsChart.update('none');
            },
        },
    });

    // Reset on mouse leave
    canvas.addEventListener('mouseleave', () => {
        yearsChart.data.datasets[0].backgroundColor = [...baseColors];
        yearsChart.update('none');
    });

    // Click handler — open lightbox
    canvas.addEventListener('click', (e) => {
        const elements = yearsChart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, true);
        if (elements.length) {
            openYearLightbox(byYear[elements[0].index]);
        }
    });

    // Pointer cursor on hoverable bars
    canvas.style.cursor = 'default';
    canvas.addEventListener('mousemove', (e) => {
        const elements = yearsChart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, true);
        canvas.style.cursor = elements.length ? 'pointer' : 'default';
    });
}

// -------------------------------------------------------------------
// Artist Lightbox
// -------------------------------------------------------------------

function openArtistLightbox(artistData) {
    const overlay = document.getElementById('lightbox-overlay');
    const title   = document.getElementById('lightbox-title');
    const tracks  = document.getElementById('lightbox-tracks');
    const close   = document.getElementById('lightbox-close');

    title.textContent = artistData.name;
    tracks.innerHTML = '';

    for (const t of artistData.tracks) {
        tracks.appendChild(el('div', { className: 'lightbox-track-row' }, [
            el('span', { className: 'lightbox-track-name', text: t.name }),
            el('span', { className: 'lightbox-track-album', text: t.album }),
        ]));
    }

    overlay.style.display = 'flex';

    // Dismiss handlers
    const dismiss = () => { overlay.style.display = 'none'; };

    close.onclick = dismiss;
    overlay.onclick = (e) => {
        if (e.target === overlay) dismiss();
    };

    // Escape key
    const escHandler = (e) => {
        if (e.key === 'Escape') {
            dismiss();
            document.removeEventListener('keydown', escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);
}

function openYearLightbox(yearData) {
    const overlay = document.getElementById('lightbox-overlay');
    const title   = document.getElementById('lightbox-title');
    const tracks  = document.getElementById('lightbox-tracks');
    const close   = document.getElementById('lightbox-close');

    title.textContent = String(yearData.year);
    tracks.innerHTML = '';

    for (const t of (yearData.tracks || [])) {
        tracks.appendChild(el('div', { className: 'lightbox-track-row' }, [
            el('span', { className: 'lightbox-track-name', text: t.name }),
            el('span', { className: 'lightbox-track-album', text: t.artist }),
        ]));
    }

    overlay.style.display = 'flex';

    const dismiss = () => { overlay.style.display = 'none'; };

    close.onclick = dismiss;
    overlay.onclick = (e) => {
        if (e.target === overlay) dismiss();
    };

    const escHandler = (e) => {
        if (e.key === 'Escape') {
            dismiss();
            document.removeEventListener('keydown', escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);
}
