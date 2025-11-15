// Sistema de fingerprint para identificar dispositivo único
class DeviceFingerprint {
    constructor() {
        this.components = [];
    }

    async generate() {
        await this.collectComponents();
        const fingerprint = this.hash(this.components.join('|||'));
        localStorage.setItem('device_fp', fingerprint);
        return fingerprint;
    }

    async collectComponents() {
        this.components = [];
        
        // User Agent
        this.components.push(navigator.userAgent);
        
        // Idioma
        this.components.push(navigator.language);
        
        // Timezone
        this.components.push(Intl.DateTimeFormat().resolvedOptions().timeZone);
        
        // Resolução de tela
        this.components.push(`${screen.width}x${screen.height}x${screen.colorDepth}`);
        
        // Canvas fingerprint
        this.components.push(await this.getCanvasFingerprint());
        
        // WebGL
        this.components.push(this.getWebGLFingerprint());
        
        // Fontes disponíveis (simplificado)
        this.components.push(this.getFontsFingerprint());
        
        // Plugins (se disponível)
        if (navigator.plugins) {
            const plugins = Array.from(navigator.plugins)
                .map(p => p.name)
                .join(',');
            this.components.push(plugins);
        }
        
        // Touch support
        this.components.push(navigator.maxTouchPoints || 0);
        
        // Platform
        this.components.push(navigator.platform);
        
        // Hardware concurrency
        this.components.push(navigator.hardwareConcurrency || 0);
        
        // Device memory (se disponível)
        this.components.push(navigator.deviceMemory || 0);
    }

    async getCanvasFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = 200;
            canvas.height = 50;
            
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillStyle = '#069';
            ctx.fillText('Gala 2025', 2, 15);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('Votação', 4, 17);
            
            return canvas.toDataURL();
        } catch (e) {
            return 'canvas_error';
        }
    }

    getWebGLFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            
            if (!gl) return 'no_webgl';
            
            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
                return gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
            }
            
            return 'webgl_available';
        } catch (e) {
            return 'webgl_error';
        }
    }

    getFontsFingerprint() {
        const baseFonts = ['monospace', 'sans-serif', 'serif'];
        const testFonts = [
            'Arial', 'Verdana', 'Times New Roman', 'Courier New',
            'Georgia', 'Palatino', 'Garamond', 'Bookman', 'Comic Sans MS',
            'Trebuchet MS', 'Impact'
        ];
        
        const testString = 'mmmmmmmmmmlli';
        const testSize = '72px';
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        
        const baselines = {};
        baseFonts.forEach(font => {
            ctx.font = testSize + ' ' + font;
            baselines[font] = ctx.measureText(testString).width;
        });
        
        const detected = testFonts.filter(font => {
            return baseFonts.some(baseFont => {
                ctx.font = testSize + ' ' + font + ',' + baseFont;
                return ctx.measureText(testString).width !== baselines[baseFont];
            });
        });
        
        return detected.join(',');
    }

    hash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return Math.abs(hash).toString(36);
    }

    static async get() {
        // Tenta recuperar do localStorage primeiro
        let fingerprint = localStorage.getItem('device_fp');
        
        if (!fingerprint) {
            const fp = new DeviceFingerprint();
            fingerprint = await fp.generate();
        }
        
        return fingerprint;
    }
}

// Exportar para uso global
window.DeviceFingerprint = DeviceFingerprint;