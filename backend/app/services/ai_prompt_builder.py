"""AI prompt builder - port of PHP `AiPromptBuilder` (teacher persona)."""

_QUALITY_RULES = """QUESTION QUALITY (apply to EVERY question before outputting it):
- Exactly one option is correct; the other three are plausible distractors.
- Distractors must be related to the topic, similar in style and length to the
  correct answer, and clearly wrong only to someone who lacks understanding.
- Never use random letters, symbols, gibberish or obviously silly options.
- Wording must be clear and free of corrupted text, stray symbols, broken
  brackets and excessive punctuation.
- No two questions may test the same fact; no duplicate answer options.
- Questions must be educational and answerable from the knowledge at hand.
If any question fails these checks after your self-review, rewrite it."""


def _difficulty_rules(difficulty: str) -> str:
    if difficulty == "easy":
        return (
            "DIFFICULTY (Easy): test basic concepts, definitions, terminology "
            "and fundamental understanding. Use clear, direct wording."
        )
    if difficulty == "hard":
        return (
            "DIFFICULTY (Hard): test analysis, reasoning, multi-step problems, "
            "edge cases and deeper understanding. Challenge the student through "
            "substance — never through confusing or trick wording."
        )
    return (
        "DIFFICULTY (Medium): test application, comparisons, relationships, "
        "examples and short scenarios."
    )


def _question_contract(n: int) -> str:
    return (
        f"Each question must be a JSON object with EXACTLY these keys:\n"
        '- "question": the question text (string)\n'
        '- "options": an array of EXACTLY 4 strings (the answer choices)\n'
        '- "correct": the 0-based index (0 to 3) of the correct option\n'
        '- "explanation": a short, clear explanation of the correct answer (string)\n'
        '- "concept": a short concept/topic label this question tests (string)\n\n'
        f"Return ONLY a single JSON array of question objects. No markdown, no extra\n"
        f"text, no trailing commas. Ensure exactly {n} objects.\n"
        "Correct answers must index into the options array correctly."
    )


def build_prompt(
    topic: str,
    difficulty: str,
    num_questions: int,
    source_text: str | None = None,
) -> str:
    label = difficulty.capitalize()
    if source_text:
        return f"""You are an experienced university teacher and professional assessment designer.

A student uploaded the study material below. Read it for UNDERSTANDING:
identify its main concepts, definitions, important facts, relationships,
processes, examples and applications. Then write multiple-choice questions
that test a student's understanding of that knowledge.

The material is a KNOWLEDGE SOURCE, not text to pattern-match. Every question
and every answer must be grounded in what the material TEACHES.

Study material ("{topic}"):
----------------------------------------
{source_text}
----------------------------------------

STRICT RULES ABOUT THE SOURCE:
- NEVER ask about the document's formatting, layout, structure, page numbers,
  headers/footers or metadata.
- NEVER ask which letters, characters, symbols, brackets or sequences "appear",
  "occur", "repeat" or are "found" in the text.
- IGNORE any corrupted fragments, random symbols, gibberish strings or
  encoding artifacts in the material; they are extraction noise, not content.
- Do NOT introduce facts, terms or ideas that are not present in the material.
- If the material does not contain enough meaningful educational content to
  write real knowledge questions, return an empty JSON array: []

Difficulty: {label}
Number of multiple-choice questions: {num_questions}

{_difficulty_rules(difficulty)}

{_QUALITY_RULES}

{_question_contract(num_questions)}"""

    return f"""You are an experienced university teacher and professional assessment designer.

Topic: {topic}
Difficulty: {label}
Number of multiple-choice questions: {num_questions}

FIRST, mentally outline the main concepts, facts, definitions, relationships,
processes and examples a good course on this topic would cover. THEN write
{num_questions} multiple-choice questions that test those concepts.

{_difficulty_rules(difficulty)}

{_QUALITY_RULES}

{_question_contract(num_questions)}"""
