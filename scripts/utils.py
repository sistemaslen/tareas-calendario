import re
from datetime import date, timedelta


def start_of_week(d: date) -> date:
    return d - timedelta(days=d.weekday())


def add_business_days(d: date, business_days: int) -> date:
    result = d
    # If start day is weekend, advance to next Monday
    while result.weekday() >= 5:
        result += timedelta(days=1)
    counted = 1
    while counted < business_days:
        result += timedelta(days=1)
        if result.weekday() < 5:
            counted += 1
    return result


def get_week_bucket(due: date, today: date) -> str:
    week_start = start_of_week(today)
    week_end = week_start + timedelta(days=6)
    next_start = week_start + timedelta(days=7)
    next_end = week_start + timedelta(days=13)

    if due < today:
        return 'VENCIDA'
    if week_start <= due <= week_end:
        return 'ACTUAL'
    if next_start <= due <= next_end:
        return 'PROXIMA'
    return 'FUTURA'


def normalize_text(value: str) -> str:
    value = value.strip().upper()
    for src, dst in [('Á','A'),('É','E'),('Í','I'),('Ó','O'),('Ú','U'),
                     ('á','a'),('é','e'),('í','i'),('ó','o'),('ú','u')]:
        value = value.replace(src, dst)
    return value


def role_matches(role_name: str, expected: str) -> bool:
    norm_role = normalize_text(role_name)
    norm_exp  = normalize_text(expected)
    if norm_role == norm_exp:
        return True
    if norm_exp == 'RH' and 'RECURSOS HUMANOS' in norm_role:
        return True
    if norm_exp == 'ADMIN' and 'ADMIN' in norm_role:
        return True
    return False
