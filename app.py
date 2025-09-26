import os
import uuid
from datetime import datetime
from pathlib import Path

from flask import Flask, flash, redirect, render_template, request, send_from_directory, url_for
from openai import OpenAI, OpenAIError

app = Flask(__name__)
app.secret_key = os.environ.get("FLASK_SECRET_KEY", "dev-secret-key")

BASE_DIR = Path(__file__).resolve().parent
OUTPUT_DIR = BASE_DIR / "mp3"
OUTPUT_DIR.mkdir(exist_ok=True)


def list_audio_files():
    files = []
    for file_path in sorted(OUTPUT_DIR.glob("*.mp3"), key=os.path.getmtime, reverse=True):
        stat = file_path.stat()
        files.append(
            {
                "name": file_path.name,
                "size_kb": f"{stat.st_size / 1024:.1f}",
                "created": datetime.fromtimestamp(stat.st_mtime).strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    return files


@app.route("/")
def index():
    return render_template("index.html", saved_files=list_audio_files())


@app.route("/generate", methods=["POST"])
def generate_audio():
    api_key = request.form.get("api_key", "").strip()
    model = request.form.get("model", "").strip()
    voice = request.form.get("voice", "").strip()
    text = request.form.get("text", "").strip()
    file_name = request.form.get("file_name", "").strip()

    if not api_key:
        flash("Необходимо указать API ключ OpenAI.", "error")
        return redirect(url_for("index"))

    if not text:
        flash("Введите текст для синтеза речи.", "error")
        return redirect(url_for("index"))

    if not model:
        flash("Выберите модель.", "error")
        return redirect(url_for("index"))

    if not voice:
        flash("Выберите голос.", "error")
        return redirect(url_for("index"))

    client = OpenAI(api_key=api_key)

    try:
        response = client.audio.speech.create(
            model=model,
            voice=voice,
            input=text,
            format="mp3",
        )
    except OpenAIError as exc:
        flash(f"Ошибка OpenAI: {exc}", "error")
        return redirect(url_for("index"))

    audio_bytes = response.read()
    if not file_name:
        file_name = datetime.now().strftime("tts-%Y%m%d-%H%M%S")
    safe_name = "".join(ch for ch in file_name if ch.isalnum() or ch in ("-", "_")) or str(uuid.uuid4())
    output_path = OUTPUT_DIR / f"{safe_name}.mp3"

    output_path.write_bytes(audio_bytes)
    flash(f"Аудиофайл сохранён как {output_path.name}", "success")
    return redirect(url_for("index"))


@app.route("/mp3/<path:filename>")
def download_file(filename):
    return send_from_directory(OUTPUT_DIR, filename, as_attachment=True)


if __name__ == "__main__":
    app.run(debug=True, host="0.0.0.0", port=int(os.environ.get("PORT", 5000)))
