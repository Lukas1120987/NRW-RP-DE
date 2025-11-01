(function() {
    const cfg = window.bannerConfig || {};
    const text = cfg.text || "Wir sind umgezogen. Ihr findet uns nun ganz einfach im Web.";
    const link = cfg.link || "https://nrw-roleplay.de";
    const color1 = cfg.color1 || getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || "#5865F2";
    const color2 = cfg.color2 || getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || "#7289da";

    // Banner-Element
    const banner = document.createElement("div");
    banner.className = "app-banner";
    banner.innerHTML = `
        <span class="banner-text">${text}</span>
        <a href="${link}" class="banner-link">Zur neuen Seite</a>
        <button id="closeBanner" class="banner-close" aria-label="Banner schließen">✖</button>
    `;

    document.body.prepend(banner);

    // Platz für Banner schaffen
    document.body.style.marginTop = "60px";

    // Schließen-Event
    document.getElementById("closeBanner").addEventListener("click", () => {
        banner.style.opacity = "0";
        banner.style.transform = "translateY(-20px)";
        setTimeout(() => {
            banner.remove();
            document.body.style.marginTop = "0";
        }, 400);
    });

    // Styles hinzufügen
    const style = document.createElement("style");
    style.textContent = `
        .app-banner {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: linear-gradient(90deg, ${color1}, ${color2});
            backdrop-filter: blur(12px);
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            padding: 1rem 2rem;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            letter-spacing: 0.4px;
            z-index: 9999;
            box-shadow: 0 2px 10px var(--shadow);
            animation: slideFadeIn 0.6s ease-out;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .banner-text {
            color: var(--text-light);
        }

        .banner-link {
            background: rgba(255,255,255,0.1);
            color: var(--text);
            padding: 0.5rem 1.2rem;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 600;
            box-shadow: 0 0 6px rgba(88,101,242,0.4);
        }
        .banner-link:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-1px);
        }

        .banner-close {
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 1.1rem;
            cursor: pointer;
            transition: color 0.3s, transform 0.2s;
        }
        .banner-close:hover {
            color: #fff;
            transform: scale(1.1);
        }

        @keyframes slideFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 600px) {
            .app-banner {
                flex-direction: column;
                text-align: center;
                gap: 0.6rem;
                padding: 0.8rem 1rem;
            }
        }
    `;
    document.head.appendChild(style);
})();
