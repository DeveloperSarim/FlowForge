"""Synthesises the promo's music and sound effects (no samples, so no licensing questions) and writes
public/audio/soundtrack.wav. Every cue is placed on the frame where its animation happens; scene timing
comes from src/timeline.json, the same file the video reads.

    python3 audio/make_soundtrack.py   (then ffmpeg loudnorm, see the bottom)
"""
import json
from pathlib import Path

import numpy as np
from scipy.io import wavfile
from scipy.signal import butter, fftconvolve, sawtooth, sosfilt

ROOT = Path(__file__).resolve().parent.parent
TL = json.loads((ROOT / 'src/timeline.json').read_text())
FPS, BPM, T = TL['fps'], TL['bpm'], TL['transition']
STARTS, f = {}, 0
for s in TL['scenes']:
    STARTS[s['id']] = f
    f += s['frames'] - T
TOTAL_FRAMES = f + T
SR = 48000
DUR = TOTAL_FRAMES / FPS
BEAT = 60 / BPM
N = int(DUR * SR)
rng = np.random.default_rng(7)

dry = np.zeros((N, 2))
verb = np.zeros((N, 2))  # sent to the reverb


def sec(scene, frame):
    return (STARTS[scene] + frame) / FPS


def tt(d):
    return np.arange(int(d * SR)) / SR


def filt(x, kind, freq, order=2):
    return sosfilt(butter(order, freq, btype=kind, fs=SR, output='sos'), x, axis=0)


def noise(d):
    return rng.standard_normal(int(d * SR))


def place(sig, at, gain=1.0, pan=0.0, send=0.0):
    """Mix a mono or stereo signal into the track at `at` seconds."""
    if sig.ndim == 1:
        sig = np.stack([sig * np.sqrt((1 - pan) / 2), sig * np.sqrt((1 + pan) / 2)], axis=1)
    i = int(at * SR)
    if i >= N or i + len(sig) <= 0:
        return
    sig = sig[max(0, -i): N - i]
    i = max(0, i)
    dry[i:i + len(sig)] += sig * gain
    verb[i:i + len(sig)] += sig * gain * send


def note(n):  # MIDI → Hz
    return 440 * 2 ** ((n - 69) / 12)


# ---------- instruments ----------

def pad(notes, d, cutoff):
    t = tt(d)
    out = np.zeros((len(t), 2))
    for n in notes:
        for ch, cents in ((0, (-9, 0, 6)), (1, (-5, 0, 10))):
            for c in cents:
                out[:, ch] += sawtooth(2 * np.pi * note(n) * 2 ** (c / 1200) * t + rng.uniform(0, 6.28))
    out = filt(filt(out, 'low', cutoff), 'high', 170)  # keep the low mids clear for the bass and kick
    env = np.minimum(1, t / 0.35) * np.minimum(1, (d - t) / 0.5).clip(0)
    return out * env[:, None] / (len(notes) * 3)


def pluck(n, d=0.4):
    t = tt(d)
    fr = note(n)
    x = np.sin(2 * np.pi * fr * t) + 0.35 * np.sin(4 * np.pi * fr * t) + 0.2 * filt(sawtooth(2 * np.pi * fr * t), 'low', 2500)
    return x * np.exp(-t * 9) * np.minimum(1, t / 0.003)


def kick():
    t = tt(0.45)
    freq = 46 + 115 * np.exp(-t * 30)
    body = np.sin(2 * np.pi * np.cumsum(freq) / SR) * np.exp(-t * 7)
    click = filt(noise(0.45), 'high', 2500) * np.exp(-t * 350) * 0.25
    return body + click


def clap():
    t = tt(0.32)
    n = filt(noise(0.32), 'band', [900, 2600])
    e = sum(np.where(t >= o, np.exp(-(t - o) * 140), 0) for o in (0, 0.011, 0.023)) + 0.55 * np.where(t >= 0.03, np.exp(-(t - 0.03) * 16), 0)
    return n * e * 0.6


def hat(open_=False):
    d = 0.22 if open_ else 0.07
    t = tt(d)
    return filt(noise(d), 'high', 7500) * np.exp(-t * (14 if open_ else 60))


def bass(n, d):
    t = tt(d)
    fr = note(n)
    x = np.sin(2 * np.pi * fr * t) + 0.4 * filt(sawtooth(2 * np.pi * fr * t), 'low', 420)
    return x * np.minimum(1, t / 0.006) * np.exp(-t * 2.2) * np.minimum(1, (d - t) / 0.03).clip(0)


def impact():
    t = tt(2.2)
    freq = 30 + 40 * np.exp(-t * 3)
    sub = np.sin(2 * np.pi * np.cumsum(freq) / SR) * np.exp(-t * 1.6)
    hit = filt(noise(2.2), 'low', 1400) * np.exp(-t * 7) * 0.6
    return sub + hit


def bands_sweep(d, centers, env):
    """Filtered noise whose brightness follows `centers` (a sweep built from crossfaded bands)."""
    t = tt(d)
    out = np.zeros(len(t))
    edges = np.geomspace(250, 9000, 11)
    pos = np.interp(t, np.linspace(0, d, len(centers)), np.log(centers))
    src = noise(d)
    for lo, hi in zip(edges[:-1], edges[1:]):
        mid = np.log(np.sqrt(lo * hi))
        weight = np.exp(-((pos - mid) ** 2) / 0.18)
        out += filt(src, 'band', [lo, hi]) * weight
    return out * env(t)


def whoosh(d=0.6, up=True):
    centers = [500, 3200, 900] if up else [2800, 700]
    sig = bands_sweep(d, centers, lambda t: np.sin(np.pi * t / d) ** 2)
    pan = np.linspace(-0.6, 0.6, len(sig))
    return np.stack([sig * np.sqrt((1 - pan) / 2), sig * np.sqrt((1 + pan) / 2)], axis=1)


def riser(d):
    sig = bands_sweep(d, [300, 1200, 6000], lambda t: (t / d) ** 2.2)
    t = tt(d)
    tone = np.sin(2 * np.pi * np.cumsum(220 + 660 * (t / d) ** 2) / SR) * (t / d) ** 2 * 0.25
    return sig + tone


def pop(fr=700, d=0.13):
    t = tt(d)
    x = np.sin(2 * np.pi * np.cumsum(fr * (1 + 0.7 * np.exp(-t * 55))) / SR) * np.exp(-t * 32)
    return x + filt(noise(d), 'high', 3000) * np.exp(-t * 400) * 0.15


def tick(fr=2600):
    t = tt(0.025)
    return np.sin(2 * np.pi * fr * t) * np.exp(-t * 380)


def key():
    t = tt(0.05)
    lo, hi = rng.uniform(1500, 2200), rng.uniform(5000, 7000)
    return filt(noise(0.05), 'band', [lo, hi]) * np.exp(-t * 260) + np.sin(2 * np.pi * rng.uniform(150, 210) * t) * np.exp(-t * 90) * 0.35


def bell(fr, d=1.4):
    t = tt(d)
    parts = [(1, 1, 3.2), (2.0, 0.45, 5), (3.01, 0.22, 7), (4.17, 0.1, 9)]
    return sum(a * np.sin(2 * np.pi * fr * m * t) * np.exp(-t * k) for m, a, k in parts) * np.minimum(1, t / 0.002)


def swish(d=0.3):
    return bands_sweep(d, [1500, 6000], lambda t: np.sin(np.pi * t / d) ** 2)


# ---------- music ----------
AM, F, C, G = [57, 60, 64], [53, 57, 60], [55, 60, 64], [55, 59, 62]
ROOT_OF = {id(AM): 45, id(F): 41, id(C): 48, id(G): 43}
kicks = []


def b2s(b):
    return b * BEAT


def chord_bar(ch, b0, beats, cutoff, gain=0.27, arp=True, bass_on=True):
    place(pad(ch + [ch[0] + 12], b2s(beats) + 0.4, cutoff), b2s(b0), gain, send=0.35)
    root = ROOT_OF[id(ch)]
    if bass_on:
        for k in range(int(beats * 2)):
            nn = root + (12 if k % 4 == 3 else 0)
            place(bass(nn, BEAT / 2 * 0.95), b2s(b0 + k / 2), 0.42 if k % 2 == 0 else 0.3)
    if arp:
        seq = [ch[0] + 12, ch[1] + 12, ch[2] + 12, ch[1] + 24]
        for k in range(int(beats * 2)):
            place(pluck(seq[k % 4]), b2s(b0 + k / 2), 0.1, pan=(-0.4 if k % 2 else 0.4), send=0.4)


def drums(b0, b1, claps=True, hats=True, every=1):
    b = b0
    while b < b1:
        if (b - b0) % every == 0:
            place(kick(), b2s(b), 0.95)
            kicks.append(b2s(b))
        if claps and (b - b0) % 2 == 1:
            place(clap(), b2s(b), 0.45, send=0.25)
        if hats:
            place(hat(open_=(b - b0) % 4 == 3), b2s(b + 0.5), 0.16, pan=0.25)
            place(hat(), b2s(b + 0.25), 0.06, pan=-0.3)
        b += 1


# Intro + problem (beats 0–12): pads opening up, arpeggio joins
chord_bar(AM, 0, 4, 900, arp=False, bass_on=False)
chord_bar(F, 4, 4, 1300, bass_on=False)
chord_bar(C, 8, 4, 1700, bass_on=False)
place(riser(1.5), b2s(9.5), 0.3)

# Hook (12–17): impact, then a dark drone and a riser into the drop
place(impact(), b2s(12), 0.9, send=0.3)
place(pad([45, 52, 57], b2s(5) + 0.3, 500), b2s(12), 0.4, send=0.3)
for b in (13, 14, 15, 16):
    place(kick(), b2s(b), 0.35)
place(riser(1.0), b2s(16), 0.35)

# Drop (17–57): full groove, Am F C G
prog = [AM, F, C, G]
for i, b in enumerate(range(17, 57, 4)):
    chord_bar(prog[i % 4], b, 4, 2400)
drums(17, 57)

# Cron, the dark scene (57–66): filtered, half-time
chord_bar(AM, 57, 4, 650, gain=0.36, arp=False)
chord_bar(F, 61, 5, 650, gain=0.36, arp=False)
drums(57, 66, claps=False, hats=False, every=2)
place(riser(1.0), b2s(64), 0.3)

# Features + phones (66–82): back to full
for i, b in enumerate(range(66, 82, 4)):
    chord_bar(prog[i], b, 4, 2700)
drums(66, 82)

# Outro (82–end): resolve on C with a bell arpeggio
place(impact(), b2s(82), 0.8, send=0.35)
place(pad([48, 55, 60, 64, 67], DUR - b2s(82), 2000), b2s(82), 0.42, send=0.45)
place(bass(36, 3.0), b2s(82), 0.5)
for k, nn in enumerate([72, 76, 79, 84, 88]):
    place(bell(note(nn)), b2s(82.5) + k * 0.28, 0.1, pan=(k - 2) * 0.25, send=0.5)

# Sidechain: pads and bass duck under each kick, the "pumping" feel
duck = np.ones(N)
tN = np.arange(N) / SR
for k in kicks:
    i = int(k * SR)
    seg = tN[i:i + int(0.35 * SR)] - k
    duck[i:i + len(seg)] = np.minimum(duck[i:i + len(seg)], 1 - 0.45 * np.exp(-seg * 11))
music_dry, music_verb = dry.copy(), verb.copy()
dry[:] = 0
verb[:] = 0

# ---------- sound effects, on the frames of the animations ----------
FX = 1.0
# transitions
for sid in ('problem', 'meet', 'builder', 'apps', 'ai', 'cron', 'features', 'mobile'):
    place(whoosh(0.6), STARTS[sid] / FPS - 0.18, 0.38 * FX, send=0.25)

# intro
place(whoosh(0.9), 0, 0.25, send=0.3)
place(pop(320, 0.2), sec('intro', 3), 0.45, send=0.3)
place(swish(0.45), sec('intro', 9), 0.18)
place(pop(1250), sec('intro', 27), 0.3, send=0.3)
for i in range(9):
    place(tick(2200 + i * 60), sec('intro', 19 + i * 2), 0.05, pan=-0.4 + i * 0.1)

# problem: the strike-throughs
place(swish(0.32), sec('problem', 30), 0.35, pan=-0.2)
place(swish(0.32), sec('problem', 76), 0.35, pan=0.2)

# meet: the dashboard rises, then the camera zooms in
place(whoosh(0.8), sec('meet', 6), 0.3, send=0.2)
place(whoosh(1.4, up=False), sec('meet', 52), 0.16, send=0.3)

# builder
for k, fr in enumerate((8, 38, 68)):
    place(pop(520 + k * 120), sec('builder', fr), 0.4, pan=(k - 1) * 0.5, send=0.25)
for fr in (32, 62):
    place(swish(0.7), sec('builder', fr), 0.14)
place(pop(400), sec('builder', 72), 0.3)
place(tick(1400), sec('builder', 89), 0.4)
place(pop(260, 0.18), sec('builder', 89), 0.35)
place(whoosh(2.0), sec('builder', 96), 0.12)
for k, (fr, nn) in enumerate(zip((98, 127, 156), (88, 91, 96))):
    place(bell(note(nn)), sec('builder', fr), 0.16, pan=(k - 1) * 0.5, send=0.4)

# apps: the counter ticks (fast, then slowing) and the logos pop in waves
last = -1
for fr in range(6, 63):
    v = 1190 * (1 - (1 - (fr - 6) / 56) ** 3)
    if int(v // 40) != last:
        last = int(v // 40)
        place(tick(1800 + v * 0.8), sec('apps', fr), 0.07)
for w in range(8):
    place(pop(600 + w * 70), sec('apps', 10 + w * 4), 0.18, pan=0.3 + w * 0.05, send=0.2)

# ai builder: typing, send, steps finishing
prompt_len = 83
typed = 0
for fr in range(14, 75):
    now = int(prompt_len * (fr - 14) / 60)
    if now > typed:
        for c in range(min(2, now - typed)):
            place(key(), sec('ai', fr) + c * 0.016 + rng.uniform(0, 0.01), rng.uniform(0.1, 0.17), pan=rng.uniform(-0.2, 0.2))
        typed = now
place(tick(1500), sec('ai', 80), 0.35)
place(pop(500, 0.18), sec('ai', 85), 0.4, send=0.3)
for k, (fr, nn) in enumerate(zip((112, 128, 144, 160), (84, 88, 91, 96))):
    place(bell(note(nn)), sec('ai', fr), 0.13, send=0.4)

# cron: mechanical typing, enter, chips
typed = 0
for fr in range(18, 59):
    now = int(34 * (fr - 18) / 40)
    if now > typed:
        place(key(), sec('cron', fr), rng.uniform(0.14, 0.2))
        typed = now
place(pop(180, 0.2), sec('cron', 60), 0.45)
for k, fr in enumerate((68, 74, 80, 86)):
    place(pop(560 + k * 90), sec('cron', fr), 0.25, pan=-0.45 + k * 0.3, send=0.2)

# features: cards land
for i in range(6):
    place(pop(480 + i * 70, 0.16), sec('features', 12 + i * 7), 0.28, pan=(i % 3 - 1) * 0.5, send=0.25)

# phones
for k, fr in enumerate((6, 14, 22)):
    place(whoosh(0.5), sec('mobile', fr), 0.18, send=0.2)

# outro: chips and the final shimmer
for k, fr in enumerate((42, 47, 52, 57)):
    place(pop(620 + k * 80), sec('outro', fr), 0.22, pan=-0.45 + k * 0.3, send=0.25)
for k, nn in enumerate((84, 88, 91, 96)):
    place(bell(note(nn), 1.8), sec('outro', 64) + k * 0.06, 0.1, pan=(k - 1.5) * 0.3, send=0.6)

fx_dry, fx_verb = dry, verb

# ---------- mix ----------
t_ir = tt(1.8)
ir = np.stack([filt(noise(1.8), 'low', 6000) * np.exp(-t_ir * 3.2) for _ in range(2)], axis=1)
ir /= np.abs(ir).sum(axis=0).max() / 6


def reverb(x):
    return np.stack([fftconvolve(x[:, c], ir[:, c])[:N] for c in range(2)], axis=1)


music = (music_dry + reverb(music_verb) * 0.5) * duck[:, None]
mix = music * 0.85 + fx_dry + reverb(fx_verb) * 0.5
mix = filt(mix, 'high', 28)
fade = np.minimum(1, (DUR - tN) / 1.6).clip(0)[:, None]
mix *= fade
mix /= np.abs(mix).max() / 0.9
mix = np.tanh(mix * 1.3) / np.tanh(1.3)

out = ROOT / 'public/audio/soundtrack.raw.wav'
out.parent.mkdir(parents=True, exist_ok=True)
wavfile.write(out, SR, mix.astype(np.float32))
print(f'{out} {DUR:.2f}s, {len(kicks)} kicks, scene starts: {STARTS}')
# Loudness for social platforms (-14 LUFS):
# ffmpeg -y -i public/audio/soundtrack.raw.wav -af loudnorm=I=-14:TP=-1.5:LRA=11 -ar 48000 -c:a pcm_s16le public/audio/soundtrack.wav
