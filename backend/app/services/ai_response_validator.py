"""AI response validator - port of PHP `AiResponseValidator` (structure + quality)."""

from typing import Any

from app.services.study_material_cleaner import StudyMaterialCleaner

OPTION_COUNT = 4


def validate(payload: Any, expected: int) -> list[str]:
    if not isinstance(payload, list):
        return ["The AI response did not contain a list of questions."]

    problems: list[str] = []
    if len(payload) != expected:
        problems.append(f"Expected {expected} questions but received {len(payload)}.")

    seen_questions: dict[str, bool] = {}

    for i, q in enumerate(payload):
        n = i + 1
        if not isinstance(q, dict):
            problems.append(f"Question {n} is not a valid object.")
            continue

        q_text = q.get("question")
        q_text = q_text.strip() if isinstance(q_text, str) else ""

        if q_text == "":
            problems.append(f"Question {n} is missing its text.")
        elif not StudyMaterialCleaner.is_clean_text(q_text):
            problems.append(f"Question {n} contains corrupted or meaningless text.")
        elif StudyMaterialCleaner.is_pattern_question(q_text):
            problems.append(
                f"Question {n} asks about text patterns or formatting instead of knowledge."
            )

        norm = re_sub_non_alnum(q_text.lower())
        if norm and norm in seen_questions:
            problems.append(f"Question {n} duplicates an earlier question.")
        seen_questions[norm] = True

        options = q.get("options")
        if not isinstance(options, list) or len(options) != OPTION_COUNT:
            problems.append(f"Question {n} must have exactly {OPTION_COUNT} options.")
        else:
            all_text = True
            seen_opts: dict[str, bool] = {}
            for opt in options:
                if not isinstance(opt, str) or opt.strip() == "":
                    all_text = False
                    break
                if not StudyMaterialCleaner.is_clean_text(opt):
                    all_text = False
                    problems.append(f"Question {n} has a corrupted or meaningless option.")
                    break
                opt_norm = opt.strip().lower()
                if opt_norm in seen_opts:
                    problems.append(f"Question {n} has duplicate options.")
                    break
                seen_opts[opt_norm] = True
            if not all_text:
                problems.append(f"Question {n} has empty or non-text options.")

        correct = q.get("correct")
        if not isinstance(correct, int) or isinstance(correct, bool) or not (0 <= correct <= OPTION_COUNT - 1):
            problems.append(f"Question {n} has an invalid correct answer.")

        if not q.get("explanation") or not isinstance(q.get("explanation"), str):
            problems.append(f"Question {n} is missing an explanation.")

        if not q.get("concept") or not isinstance(q.get("concept"), str):
            problems.append(f"Question {n} is missing a concept.")

    return problems


def sanitize(payload: list) -> list[dict[str, Any]]:
    clean: list[dict[str, Any]] = []
    seen_questions: dict[str, bool] = {}
    for q in payload:
        if not isinstance(q, dict):
            continue
        q_text = str(q.get("question") or "").strip()
        if (
            q_text == ""
            or not StudyMaterialCleaner.is_clean_text(q_text)
            or StudyMaterialCleaner.is_pattern_question(q_text)
        ):
            continue
        norm = re_sub_non_alnum(q_text.lower())
        if norm in seen_questions:
            continue
        seen_questions[norm] = True

        options = [
            o
            for o in (q.get("options") or [])
            if isinstance(o, str) and o.strip() != "" and StudyMaterialCleaner.is_clean_text(o)
        ]
        lowered = [o.lower() for o in options]
        if len(set(lowered)) != len(lowered):
            continue  # duplicate options

        clean.append(
            {
                "question": q_text,
                "options": options,
                "correct": int(q.get("correct") or 0),
                "explanation": str(q.get("explanation") or ""),
                "concept": str(q.get("concept") or ""),
            }
        )
    return clean


def re_sub_non_alnum(s: str) -> str:
    import re

    return re.sub(r"[^a-z0-9]+", "", s)
