<style>
.insta-hover-overlay {
  position: fixed;
  pointer-events: none;
  z-index: 9999;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%) scale(.94);
  opacity: 0;
  visibility: hidden;
  transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
  background: color-mix(in srgb, var(--primary-dark) 78%, rgba(15, 23, 42, .82));
  border: 1px solid rgba(255,255,255,.10);
  border-radius: 22px;
  padding: 14px;
  display: flex;
  gap: 12px;
  box-shadow: 0 32px 70px rgba(15, 23, 42, .34);
  backdrop-filter: blur(16px);
}
.insta-hover-overlay.active {
  opacity: 1;
  visibility: visible;
  transform: translate(-50%, -50%) scale(1);
}
.insta-hover-img {
  width: 220px;
  height: 220px;
  object-fit: cover;
  border-radius: 16px;
  display: block;
  box-shadow: 0 16px 30px rgba(15, 23, 42, .22);
}
.insta-modal {
  position: fixed;
  inset: 0;
  z-index: 10000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 28px;
  background: rgba(15, 23, 42, .72);
  backdrop-filter: blur(8px);
}
.insta-modal.active { display: flex; }
.insta-modal-close {
  position: absolute;
  top: 22px;
  right: 22px;
  width: 42px;
  height: 42px;
  border: 0;
  border-radius: 999px;
  background: rgba(255,255,255,.12);
  color: #fff;
  font-size: 22px;
  cursor: pointer;
}
.insta-modal-content {
  width: min(1120px, 100%);
  height: min(82vh, 760px);
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(320px, .86fr);
  overflow: hidden;
  border-radius: 30px;
  background: linear-gradient(180deg, rgba(255,255,255,.98), color-mix(in srgb, var(--primary-light) 14%, #fff));
  box-shadow: 0 34px 84px rgba(15, 23, 42, .32);
}
.insta-photo-col {
  min-width: 0;
  background: linear-gradient(180deg, color-mix(in srgb, var(--primary-dark) 82%, #101828), color-mix(in srgb, var(--primary-dark) 62%, var(--accent)));
}
.insta-photo-col img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.insta-comments-col {
  min-width: 0;
  display: flex;
  flex-direction: column;
  background: #fff;
}
.insta-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 22px;
  border-bottom: 1px solid color-mix(in srgb, var(--secondary) 14%, var(--gray-200));
}
.insta-comments-list {
  flex: 1;
  overflow-y: auto;
  padding: 0 22px 22px;
}
.insta-comment {
  display: flex;
  gap: 12px;
  padding: 16px 0;
  border-bottom: 1px solid color-mix(in srgb, var(--secondary) 10%, var(--gray-200));
}
.insta-comment:last-child {
  border-bottom: 0;
}
.insta-comment-avatar {
  width: 36px;
  height: 36px;
  border-radius: 999px;
  flex: 0 0 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: color-mix(in srgb, var(--primary) 20%, #fff);
  color: var(--primary-dark);
  font-weight: 800;
}
.insta-comment-content {
  min-width: 0;
  display: grid;
  gap: 4px;
}
.insta-comment-content strong {
  color: var(--gray-800);
}
.insta-comment-content span {
  color: var(--gray-600);
  line-height: 1.55;
}
@media (max-width: 960px) {
  .insta-hover-overlay {
    display: none;
  }
  .insta-modal {
    padding: 0;
  }
  .insta-modal-content {
    width: 100%;
    height: 100%;
    max-width: 100%;
    border-radius: 0;
    grid-template-columns: 1fr;
    grid-template-rows: minmax(280px, 44vh) 1fr;
  }
  .insta-modal-close {
    top: 14px;
    right: 14px;
  }
}
</style>

<div class="insta-hover-overlay" id="instaHoverOverlay" aria-hidden="true"></div>

<div class="insta-modal" id="instaModal" aria-hidden="true">
  <button class="insta-modal-close" type="button" onclick="closeInstaModal()" aria-label="Fermer">×</button>
  <div class="insta-modal-content">
    <div class="insta-photo-col">
      <img id="instaMainPhoto" src="" alt="Photographie du signalement">
    </div>
    <div class="insta-comments-col">
      <div class="insta-header">
        <div id="instaHeaderAvatar" style="width:40px;height:40px;border-radius:50%;border:2px solid transparent;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;"></div>
        <div>
          <div style="font-weight:800;font-size:15px;color:var(--gray-800);" id="instaAuthorName">-</div>
          <div style="color:var(--gray-600);font-size:13px;" id="instaMetaText">-</div>
        </div>
      </div>
      <div class="insta-comments-list" id="instaCommentsList">
        <div style="text-align:center;padding:40px;color:var(--gray-400);">Chargement...</div>
      </div>
    </div>
  </div>
</div>

<script>
let currentIncidentMediaCache = {};
let hoverTimeout = null;
let instaAbortController = null;

async function fetchIncidentMedia(id, signal) {
  if (currentIncidentMediaCache[id]) {
    return currentIncidentMediaCache[id];
  }

  const response = await fetch('/admin/index.php?page=ajax_incident_media&id=' + encodeURIComponent(id), { signal });
  if (!response.ok) {
    throw new Error('Erreur HTTP');
  }

  const data = await response.json();
  currentIncidentMediaCache[id] = data;
  return data;
}

function openLoadingModal() {
  const modal = document.getElementById('instaModal');
  document.getElementById('instaMainPhoto').src = '';
  document.getElementById('instaAuthorName').textContent = 'Chargement...';
  document.getElementById('instaMetaText').textContent = 'Veuillez patienter...';
  document.getElementById('instaCommentsList').innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);">Chargement des donnees...</div>';
  modal.classList.add('active');
  document.body.style.overflow = 'hidden';
}

function fillAndShowInstaModal(data) {
  document.getElementById('instaMainPhoto').src = data.photos[0].url;

  const author = data.meta.reporter_name || 'Inconnu';
  const avatarNode = document.getElementById('instaHeaderAvatar');
  avatarNode.textContent = author.charAt(0).toUpperCase();
  avatarNode.style.borderColor = 'transparent';
  avatarNode.style.background = 'var(--primary)';

  if (Array.isArray(data.photos) && data.photos.length > 1) {
    avatarNode.style.background = 'linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%)';
    avatarNode.style.borderColor = '#fff';
  }

  document.getElementById('instaAuthorName').textContent = author;
  document.getElementById('instaMetaText').textContent = data.meta.title || data.meta.reference || '';

  const list = document.getElementById('instaCommentsList');
  const safeAuthor = author.charAt(0).toUpperCase();
  let html = `
    <div class="insta-comment">
      <div class="insta-comment-avatar" style="background:var(--primary-dark);color:#fff">${safeAuthor}</div>
      <div class="insta-comment-content">
        <strong>${author}</strong>
        <span>${data.meta.description || 'Description du signalement'}</span>
      </div>
    </div>
  `;

  if (Array.isArray(data.comments) && data.comments.length > 0) {
    data.comments.forEach((comment) => {
      const commenter = comment.author || 'Citoyen(ne)';
      const commenterInitial = commenter.charAt(0).toUpperCase();
      html += `
        <div class="insta-comment">
          <div class="insta-comment-avatar">${commenterInitial}</div>
          <div class="insta-comment-content">
            <strong>${commenter}</strong>
            <span>${comment.content || ''}</span>
          </div>
        </div>
      `;
    });
  } else {
    html += '<div style="text-align:center;padding:36px 0;color:var(--gray-400);font-size:13px;">Aucun commentaire public ajoute.</div>';
  }

  list.innerHTML = html;
}

function closeInstaModal() {
  document.getElementById('instaModal').classList.remove('active');
  document.body.style.overflow = '';
}

document.getElementById('instaModal').addEventListener('click', (event) => {
  if (event.target.id === 'instaModal') {
    closeInstaModal();
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    document.getElementById('instaHoverOverlay').classList.remove('active');
    closeInstaModal();
  }
});

document.querySelectorAll('.js-insta-preview').forEach((trigger) => {
  const id = trigger.dataset.id;
  if (!id) {
    return;
  }

  trigger.addEventListener('mouseenter', () => {
    hoverTimeout = window.setTimeout(() => {
      if (instaAbortController) {
        instaAbortController.abort();
      }

      instaAbortController = new AbortController();
      fetchIncidentMedia(id, instaAbortController.signal)
        .then((data) => {
          if (!data || !Array.isArray(data.photos) || data.photos.length === 0) {
            return;
          }

          const overlay = document.getElementById('instaHoverOverlay');
          overlay.innerHTML = '';
          data.photos.slice(0, 3).forEach((photo) => {
            const img = document.createElement('img');
            img.src = photo.url;
            img.className = 'insta-hover-img';
            overlay.appendChild(img);
          });

          const rect = trigger.getBoundingClientRect();
          let left = rect.left + rect.width / 2;
          let top = rect.top - 150;

          if (top < window.innerHeight / 2) {
            top = rect.bottom + 150;
          }

          overlay.style.left = left + 'px';
          overlay.style.top = top + 'px';
          overlay.classList.add('active');
        })
        .catch((error) => {
          if (error.name !== 'AbortError') {
            console.error('Erreur hover preview', error);
          }
        });
    }, 140);
  });

  trigger.addEventListener('mouseleave', () => {
    window.clearTimeout(hoverTimeout);
    if (instaAbortController) {
      instaAbortController.abort();
    }
    document.getElementById('instaHoverOverlay').classList.remove('active');
  });

  trigger.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    window.clearTimeout(hoverTimeout);
    document.getElementById('instaHoverOverlay').classList.remove('active');
    openLoadingModal();

    fetchIncidentMedia(id, null)
      .then((data) => {
        if (data && data.success && Array.isArray(data.photos) && data.photos.length > 0) {
          fillAndShowInstaModal(data);
          return;
        }

        closeInstaModal();
        window.alert('Aucune photo disponible ou erreur de chargement.');
      })
      .catch((error) => {
        console.error(error);
        closeInstaModal();
        window.alert('Erreur de chargement des photos.');
      });
  });
});
</script>

