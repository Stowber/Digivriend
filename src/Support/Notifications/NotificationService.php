<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Support\Clock;
use App\Support\Env;
use DateTimeImmutable;
use PDO;

final class NotificationService
{
    private const LOGO_DATA_URI =
        'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyBpZD0iTGF5ZXJfMSIgZGF0YS1uYW1lPSJMYX' .
        'llciAxIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMTYuODUgMTE0LjU1Ij4KICA8ZGVmcz4KICAgIDxzdHlsZT' .
        '4KICAgICAgLmNscy0xIHsKICAgICAgICBmaWxsOiAjMTk2MTkxOwogICAgICB9CgogICAgICAuY2xzLTEsIC5jbHMtMiB7CiAgICAgICAgc3Ryb2tlLXdpZH' .
        'RoOiAwcHg7CiAgICAgIH0KCiAgICAgIC5jbHMtMywgLmNscy00LCAuY2xzLTUsIC5jbHMtNiB7CiAgICAgICAgZmlsbDogbm9uZTsKICAgICAgICBzdHJva2' .
        'UtbWl0ZXJsaW1pdDogMTA7CiAgICAgIH0KCiAgICAgIC5jbHMtMywgLmNscy01IHsKICAgICAgICBzdHJva2Utd2lkdGg6IC43cHg7CiAgICAgIH0KCiAgIC' .
        'AgIC5jbHMtMywgLmNscy02IHsKICAgICAgICBzdHJva2U6ICNlYzY2MjU7CiAgICAgIH0KCiAgICAgIC5jbHMtNyB7CiAgICAgICAgbGV0dGVyLXNwYWNpbm' .
        'c6IC4xNGVtOwogICAgICB9CgogICAgICAuY2xzLTIgewogICAgICAgIGZpbGw6ICNlYzY2MjU7CiAgICAgIH0KCiAgICAgIC5jbHMtNCwgLmNscy01IHsKIC' .
        'AgICAgICBzdHJva2U6ICMxOTYxOTE7CiAgICAgIH0KCiAgICAgIC5jbHMtNCwgLmNscy02IHsKICAgICAgICBzdHJva2Utd2lkdGg6IDEuMnB4OwogICAgIC' .
        'B9CgogICAgICAuY2xzLTggewogICAgICAgIGxldHRlci1zcGFjaW5nOiAuMTdlbTsKICAgICAgfQoKICAgICAgLmNscy05IHsKICAgICAgICBsZXR0ZXItc3' .
        'BhY2luZzogLjA3ZW07CiAgICAgIH0KCiAgICAgIC5jbHMtMTAgewogICAgICAgIGxldHRlci1zcGFjaW5nOiAuMTRlbTsKICAgICAgfQoKICAgICAgLmNscy' .
        '0xMSB7CiAgICAgICAgZmlsbDogIzAxMDEwMTsKICAgICAgICBmb250LWZhbWlseTogV2FudGVkU2Fuc1ZhcmlhYmxlLVJlZ3VsYXIsICdXYW50ZWQgU2Fucy' .
        'BWYXJpYWJsZSc7CiAgICAgICAgZm9udC1zaXplOiA4LjQ3cHg7CiAgICAgICAgZm9udC12YXJpYXRpb24tc2V0dGluZ3M6ICd3Z2h0JyA0MDA7CiAgICAgIH' .
        '0KCiAgICAgIC5jbHMtMTIgewogICAgICAgIGxldHRlci1zcGFjaW5nOiAuMTJlbTsKICAgICAgfQogICAgPC9zdHlsZT4KICA8L2RlZnM+CiAgPGc+CiAgIC' .
        'A8Zz4KICAgICAgPHBhdGggY2xhc3M9ImNscy0yIiBkPSJNMzIuODYsNzkuNjVjMCwxLjM5LS4zMiwyLjY5LS45NSwzLjktLjY0LDEuMi0xLjUxLDIuMjUtMi' .
        '42MSwzLjE1LTEuMS44OS0yLjM4LDEuNi0zLjg1LDIuMTItMS40Ni41Mi0zLjAyLjc3LTQuNjcuNzdoLTExLjU2bDEuOTEtMTAuNzZoNS4zNGwtMS4xMSw2Lj' .
        'MyaDYuMTljLjgzLDAsMS42LS4xMSwyLjMxLS4zNC43MS0uMjIsMS4zMy0uNTQsMS44Ni0uOTUuNTItLjQxLjk0LS45MSwxLjI1LTEuNDguMzEtLjU4LjQ2LT' .
        'EuMjEuNDYtMS45LDAtLjUzLS4xMS0xLjAyLS4zMi0xLjQ3LS4yMi0uNDUtLjUyLS44My0uOS0xLjE2LS4zOS0uMzMtLjg1LS41OC0xLjM5LS43Ni0uNTQtLj' .
        'E4LTEuMTQtLjI3LTEuNzktLjI3aC0xMS41Nmw0LjIxLTQuNDloOC4xNmMxLjM2LDAsMi41OS4xOCwzLjcuNTQsMS4xMS4zNiwyLjA2Ljg2LDIuODUsMS41MS' .
        '43OS42NSwxLjQsMS40MiwxLjgzLDIuMzEuNDMuODkuNjUsMS44OC42NSwyLjk3WiIvPgogICAgICA8cGF0aCBjbGFzcz0iY2xzLTIiIGQ9Ik0zOS4zMiw4OS' .
        '41OWgtNS4zNGwzLjA3LTE3LjI3aDUuMzJsLTMuMDUsMTcuMjdaIi8+CiAgICAgIDxwYXRoIGNsYXNzPSJjbHMtMiIgZD0iTTU1LjIxLDc4LjhoMTEuMDVsLT' .
        'EuOTEsMTAuNzloLTExLjg1Yy0xLjM0LDAtMi41Ny0uMTktMy42OC0uNTgtMS4xMS0uMzktMi4wNi0uOTItMi44NC0xLjYtLjc4LS42OC0xLjM5LTEuNDgtMS' .
        '44Mi0yLjQxLS40My0uOTMtLjY1LTEuOTQtLjY1LTMuMDIsMC0xLjQxLjMyLTIuNzEuOTUtMy45LjY0LTEuMTksMS41LTIuMjEsMi41OC0zLjA2LDEuMDgtLj' .
        'g1LDIuMzYtMS41MSwzLjgyLTEuOTksMS40Ni0uNDcsMy4wMi0uNzEsNC42Ny0uNzFoMTIuMzZsLTQuMjYsNC40OWgtOC45Yy0uNzksMC0xLjU0LjEyLTIuMj' .
        'YuMzYtLjcxLjI0LTEuMzQuNTgtMS44NywxLjAxLS41My40My0uOTYuOTQtMS4yOCwxLjUyLS4zMi41OS0uNDgsMS4yMi0uNDgsMS45MSwwLDEuMDguNCwxLj' .
        'k0LDEuMiwyLjU4LjguNjQsMS44Ny45NSwzLjIxLjk1aDYuNTZsLjQ0LTIuNWgtOC45NmwzLjktMy44NVoiLz4KICAgICAgPHBhdGggY2xhc3M9ImNscy0yIi' .
        'BkPSJNNzIuODQsODkuNTloLTUuMzRsMy4wNy0xNy4yN2g1LjMybC0zLjA1LDE3LjI3WiIvPgogICAgICA8cGF0aCBjbGFzcz0iY2xzLTEiIGQ9Ik04NC40My' .
        'w3Mi4zMmw1Ljc2LDExLjQ2LDEwLjEyLTExLjQ2aDYuMzJsLTEzLjgzLDE1LjYxYy0uNDUuNS0uOTguOTUtMS42LDEuMzRzLTEuMzUuNTktMi4xOS41OS0xLj' .
        'Q1LS4xOS0xLjkyLS41NWMtLjQ3LS4zNy0uODYtLjgzLTEuMTUtMS4zOGwtOC4wMy0xNS42MWg2LjUzWiIvPgogICAgICA8cGF0aCBjbGFzcz0iY2xzLTEiIG' .
        'Q9Ik0xMjcuOTIsODkuNTloLTcuMTVsLTMuMDUtNC41N2gtOC4yMWwtLjgsNC41N2gtNS4zNGwxLjU1LTguODNoMTUuMDJjLjQ1LDAsLjg3LS4wNSwxLjI4LS' .
        '4xNS40LS4xLjc2LS4yNSwxLjA2LS40NC4zLS4xOS41NC0uNDMuNzItLjcxLjE4LS4yOC4yNy0uNi4yNy0uOTQsMC0uNTctLjIzLS45OS0uNy0xLjI4LS40Ni' .
        '0uMjgtMS4xMS0uNDMtMS45NC0uNDNoLTE1LjAybDQuMjgtNC40OWgxMS4xYy44OSwwLDEuODEuMDgsMi43NS4yMy45NC4xNSwxLjc5LjQzLDIuNTUuODQuNz' .
        'cuNCwxLjM5Ljk0LDEuODcsMS42MS40OC42Ny43MiwxLjUyLjcyLDIuNTYsMCwuNzctLjEzLDEuNTEtLjQsMi4yMi0uMjcuNzEtLjY1LDEuMzQtMS4xNCwxLj' .
        'kxLS40OS41Ny0xLjA4LDEuMDUtMS43NywxLjQ1LS42OS40LTEuNDUuNjctMi4yNy44My4yNC4yMi41MS41MS44MS44NS4zLjM0LjY5LjgyLDEuMTcsMS40Mm' .
        'wyLjYxLDMuMzZaIi8+CiAgICAgIDxwYXRoIGNsYXNzPSJjbHMtMSIgZD0iTTEzNS4yNSw4OS41OWgtNS4zNGwzLjA3LTE3LjI3aDUuMzJsLTMuMDUsMTcuMj' .
        'daIi8+CiAgICAgIDxwYXRoIGNsYXNzPSJjbHMtMSIgZD0iTTE1OS40Myw4NS4xNWwtNC4yMyw0LjQ0aC0xNi42MmwzLjA3LTE3LjI3aDE5Ljk4bC00LjI2LD' .
        'QuNDloLTExLjE4bC0uMzYsMi4wNmgxMy42NWwtMy43MiwzLjkyaC0xMC42M2wtLjQxLDIuMzVoMTQuNzFaIi8+CiAgICAgIDxwYXRoIGNsYXNzPSJjbHMtMS' .
        'IgZD0iTTE3Ny45MSw4OS44N2MtLjM2LDAtLjY5LS4wNi0uOTktLjE3LS4zLS4xMS0uNjMtLjM3LS45OS0uNzZsLTguOS05LjUtMS43OCwxMC4xNGgtNC44NW' .
        'wyLjUtMTQuMzJjLjEtLjU3LjI3LTEuMDYuNDktMS40Ny4yMi0uNDEuNDktLjc1LjgxLTEuMDEuMzItLjI2LjY3LS40NSwxLjA1LS41Ny4zOC0uMTIuNzctLj' .
        'E4LDEuMTYtLjE4LjMzLDAsLjY1LjA2Ljk4LjE3LjMzLjExLjY2LjM3LDEuMDEuNzZsOC45LDkuNSwxLjgxLTEwLjE0aDQuODVsLTIuNTYsMTQuM2MtLjEuNT' .
        'ctLjI3LDEuMDYtLjUsMS40Ny0uMjMuNDEtLjUuNzUtLjgxLDEuMDItLjMxLjI3LS42NS40Ni0xLjAzLjU4LS4zOC4xMi0uNzYuMTgtMS4xNC4xOFoiLz4KIC' .
        'AgICAgPHBhdGggY2xhc3M9ImNscy0xIiBkPSJNMjA3LjY5LDc5LjY1YzAsMS4zOS0uMzIsMi42OS0uOTUsMy45LS42NCwxLjItMS41MSwyLjI1LTIuNjEsMy' .
        '4xNS0xLjEuODktMi4zOCwxLjYtMy44NSwyLjEyLTEuNDYuNTItMy4wMi43Ny00LjY3Ljc3aC0xMS41NmwxLjkxLTEwLjc2aDUuMzRsLTEuMTEsNi4zMmg2Lj' .
        'E5Yy44MywwLDEuNi0uMTEsMi4zMS0uMzQuNzEtLjIyLDEuMzMtLjU0LDEuODYtLjk1LjUyLS40MS45NC0uOTEsMS4yNS0xLjQ4LjMxLS41OC40Ni0xLjIxLj' .
        'Q2LTEuOSwwLS41My0uMTEtMS4wMi0uMzItMS40Ny0uMjItLjQ1LS41Mi0uODMtLjktMS4xNi0uMzktLjMzLS44NS0uNTgtMS4zOS0uNzZzLTEuMTQtLjI3LT' .
        'EuNzktLjI3aC0xMS41Nmw0LjIxLTQuNDloOC4xNmMxLjM2LDAsMi41OS4xOCwzLjcuNTQsMS4xMS4zNiwyLjA2Ljg2LDIuODUsMS41MS43OS42NSwxLjQsMS' .
        '40MiwxLjgzLDIuMzEuNDMuODkuNjUsMS44OC42NSwyLjk3WiIvPgogICAgPC9nPgogICAgPHRleHQgY2xhc3M9ImNscy0xMSIgdHJhbnNmb3JtPSJ0cmFuc2' .
        'xhdGUoOS4xNiAxMDMuMTEpIj48dHNwYW4gY2xhc3M9ImNscy03IiB4PSIwIiB5PSIwIj5CRTwvdHNwYW4+PHRzcGFuIGNsYXNzPSJjbHMtOSIgeD0iMTIuMT' .
        'IiIHk9IjAiPlQ8L3RzcGFuPjx0c3BhbiBjbGFzcz0iY2xzLTEwIiB4PSIxOC4wNiIgeT0iMCI+QUFMPC90c3Bhbj48dHNwYW4gY2xhc3M9ImNscy0xMiIgeD' .
        '0iMzcuOTIiIHk9IjAiPkI8L3RzcGFuPjx0c3BhbiBjbGFzcz0iY2xzLTEwIiB4PSI0My45NCIgeT0iMCI+QTwvdHNwYW4+PHRzcGFuIGNsYXNzPSJjbHMtOC' .
        'IgeD0iNTEiIHk9IjAiPlI8L3RzcGFuPjx0c3BhbiBjbGFzcz0iY2xzLTEwIiB4PSI1Ny4zNSIgeT0iMCI+RSBDT01QVVRFPC90c3Bhbj48dHNwYW4gY2xhc3' .
        'M9ImNscy04IiB4PSIxMTUuNzIiIHk9IjAiPlI8L3RzcGFuPjx0c3BhbiBjbGFzcz0iY2xzLTciIHg9IjEyMi4wNyIgeT0iMCI+SFVMUCBBQU4gSFVJUzwvdH' .
        'NwYW4+PC90ZXh0PgogIDwvZz4KICA8Zz4KICAgIDxnPgogICAgICA8Zz4KICAgICAgICA8bGluZSBjbGFzcz0iY2xzLTQiIHgxPSIxNTAuMTIiIHkxPSI1MS' .
        '4wNyIgeDI9IjEzNS40NiIgeTI9IjM2LjQiLz4KICAgICAgICA8cG9seWxpbmUgY2xhc3M9ImNscy00IiBwb2ludHM9IjcyLjk5IDUxLjggODUuMyAzOS40OS' .
        'A4OC42MSAzNi4xOCA5MS45NyAzMi44MiA5NS4wOCAyOS43MSAxMDguNjIgMTYuMTcgMTIxLjg2IDI5LjQxIi8+CiAgICAgICAgPGxpbmUgY2xhc3M9ImNscy' .
        '00IiB4MT0iMTQzLjc1IiB5MT0iNTEuMzEiIHgyPSIxMzIuMjYiIHkyPSIzOS44MiIvPgogICAgICAgIDxsaW5lIGNsYXNzPSJjbHMtNCIgeDE9Ijc5LjQ5Ii' .
        'B5MT0iNTIuMjgiIHgyPSI4OC44MSIgeTI9IjQyLjk2Ii8+CiAgICAgICAgPGxpbmUgY2xhc3M9ImNscy00IiB4MT0iMTM2Ljk3IiB5MT0iNTAuODgiIHgyPS' .
        'IxMjkuMDciIHkyPSI0Mi45OSIvPgogICAgICAgIDxsaW5lIGNsYXNzPSJjbHMtNCIgeDE9IjEzMS4xNSIgeTE9IjUwLjg4IiB4Mj0iMTI2LjQiIHkyPSI0Ni' .
        '4xMyIvPgogICAgICAgIDxwb2x5bGluZSBjbGFzcz0iY2xzLTQiIHBvaW50cz0iNjYuNDYgNTEuMjMgODEuNzUgMzUuOTQgODUuMDYgMzIuNjMgODguNDMgMj' .
        'kuMjcgOTEuNDYgMjYuMjQgMTA4LjM3IDkuMzIgMTI0Ljc4IDI1LjczIi8+CiAgICAgICAgPHBvbHlsaW5lIGNsYXNzPSJjbHMtNCIgcG9pbnRzPSI4OC4yMi' .
        'A0My41NSA5MS45NyAzOS44IDEwOC44IDIyLjk3IDExOC42MyAzMi41NSAxMTUuNjcgMzUuNDEgMTA5LjU0IDI5LjczIDg2LjQgNTEuOTkiLz4KICAgICAgIC' .
        'A8Y2lyY2xlIGNsYXNzPSJjbHMtNSIgY3g9IjE1MC44MiIgY3k9IjUxLjYzIiByPSIxLjA5Ii8+CiAgICAgICAgPGNpcmNsZSBjbGFzcz0iY2xzLTUiIGN4PS' .
        'IxNDQuNTMiIGN5PSI1Mi4wNiIgcj0iMS4wOSIvPgogICAgICAgIDxjaXJjbGUgY2xhc3M9ImNscy01IiBjeD0iMTM3Ljg3IiBjeT0iNTEuNzYiIHI9IjEuMD' .
        'kiLz4KICAgICAgICA8Y2lyY2xlIGNsYXNzPSJjbHMtNSIgY3g9IjEzMS44MiIgY3k9IjUxLjc2IiByPSIxLjA5Ii8+CiAgICAgICAgPHBhdGggY2xhc3M9Im' .
        'Nscy01IiBkPSJNODUuODksNTMuNzZjLS42NCwwLTEuMTUtLjU2LTEuMDgtMS4yMS4wNi0uNS40Ni0uOS45Ni0uOTYuNjYtLjA3LDEuMjEuNDQsMS4yMSwxLj' .
        'A4LDAsLjc4LS42NywxLjA5LTEuMDksMS4wOVoiLz4KICAgICAgICA8Y2lyY2xlIGNsYXNzPSJjbHMtNSIgY3g9Ijc5LjA5IiBjeT0iNTIuODkiIHI9IjEuMD' .
        'kiLz4KICAgICAgICA8Y2lyY2xlIGNsYXNzPSJjbHMtNSIgY3g9IjcyLjI3IiBjeT0iNTIuNjciIHI9IjEuMDkiLz4KICAgICAgICA8Y2lyY2xlIGNsYXNzPS' .
        'JjbHMtNSIgY3g9IjY1LjY0IiBjeT0iNTIuMTMiIHI9IjEuMDkiLz4KICAgICAgPC9nPgogICAgICA8Zz4KICAgICAgICA8bGluZSBjbGFzcz0iY2xzLTYiIH' .
        'gxPSI4NS42MiIgeTE9IjIwLjgzIiB4Mj0iOTEuNDYiIHkyPSIyNi4yNCIvPgogICAgICAgIDxsaW5lIGNsYXNzPSJjbHMtNiIgeDE9IjEyNS4wMSIgeTE9Ij' .
        'I2LjM2IiB4Mj0iMTMwLjg0IiB5Mj0iMjAuODgiLz4KICAgICAgICA8bGluZSBjbGFzcz0iY2xzLTYiIHgxPSI3OS4wOSIgeTE9IjE5Ljk0IiB4Mj0iODguND' .
        'MiIHkyPSIyOS4yNyIvPgogICAgICAgIDxwYXRoIGNsYXNzPSJjbHMtNiIgZD0iTTcyLjcsMjAuMjdzNi4wNSw2LjA1LDEyLjM2LDEyLjM2Ii8+CiAgICAgIC' .
        'AgPGxpbmUgY2xhc3M9ImNscy02IiB4MT0iODEuNzUiIHkxPSIzNS45NCIgeDI9IjY2LjI3IiB5Mj0iMjAuNDYiLz4KICAgICAgICA8cG9seWxpbmUgY2xhc3' .
        'M9ImNscy02IiBwb2ludHM9IjEwMi4yOSAzNi43IDEwOC4zNyA0Mi40OCAxMTUuNjcgMzUuNDEgMTE4LjYzIDMyLjU1IDEyMS44NiAyOS40MSAxMjUuMjEgMj' .
        'YuMTYiLz4KICAgICAgICA8Y2lyY2xlIGNsYXNzPSJjbHMtMyIgY3g9IjE1MS4yMSIgY3k9IjIwLjUyIiByPSIxLjA5Ii8+CiAgICAgICAgPGNpcmNsZSBjbG' .
        'Fzcz0iY2xzLTMiIGN4PSI2NS42NiIgY3k9IjE5Ljg4IiByPSIxLjA5Ii8+CiAgICAgICAgPGNpcmNsZSBjbGFzcz0iY2xzLTMiIGN4PSI3Mi4wMyIgY3k9Ij' .
        'E5LjY5IiByPSIxLjA5Ii8+CiAgICAgICAgPGNpcmNsZSBjbGFzcz0iY2xzLTMiIGN4PSI3OC40NSIgY3k9IjE5LjI4IiByPSIxLjA5Ii8+CiAgICAgICAgPG' .
        'NpcmNsZSBjbGFzcz0iY2xzLTMiIGN4PSI4NC44MSIgY3k9IjE5LjkiIHI9IjEuMDkiLz4KICAgICAgICA8Y2lyY2xlIGNsYXNzPSJjbHMtMyIgY3g9IjEzMS' .
        '40NCIgY3k9IjIwLjIyIiByPSIxLjA5Ii8+CiAgICAgICAgPGNpcmNsZSBjbGFzcz0iY2xzLTMiIGN4PSIxNDQuODQiIGN5PSIxOS45NCIgcj0iMS4wOSIvPg' .
        'ogICAgICAgIDxjaXJjbGUgY2xhc3M9ImNscy0zIiBjeD0iMTM4LjAxIiBjeT0iMTkuOSIgcj0iMS4wOSIvPgogICAgICA8L2c+CiAgICAgIDxwYXRoIGNsYX' .
        'NzPSJjbHMtNiIgZD0iTTk4LjcsMzkuNzdsOS42Nyw5LjY3LjE3LjE3Yy4xNS0uMTUuMzEtLjMxLjMxLS4zMWwyOC43NS0yOC43NSIvPgogICAgICA8cGF0aC' .
        'BjbGFzcz0iY2xzLTYiIGQ9Ik05NS4xNyw0My4zOGwxMy4wMSwxMy4wMS4yLjJjMy0zLDM1LjkxLTM1LjkxLDM1LjkxLTM1LjkxIi8+CiAgICAgIDxwYXRoIG' .
        'NsYXNzPSJjbHMtNiIgZD0iTTE1MC43NSwyMS4xMmwtNDIuMjgsNDIuMjgtLjI3LjI3Yy0uMjItLjIyLS40NC0uNDQtLjQ0LS40NGwtMTYuMzgtMTYuMzgiLz' .
        '4KICAgIDwvZz4KICAgIDxsaW5lIGNsYXNzPSJjbHMtNCIgeDE9Ijc3LjM2IiB5MT0iNDAuMzMiIHgyPSI5My42NyIgeTI9IjI0LjAzIi8+CiAgICA8bGluZS' .
        'BjbGFzcz0iY2xzLTQiIHgxPSI5MC40OSIgeTE9IjQ4LjA2IiB4Mj0iMTA0LjAzIiB5Mj0iMzUuMDMiLz4KICA8L2c+Cjwvc3ZnPg==';
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sendPickupReady(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $subject = $payload['subject'] ?? 'Uw apparaat staat klaar voor afhalen';
        $template = $this->renderTemplate('pickup_ready', $payload);

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $template['html']);
    }

    public function sendPickupConfirmation(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $subject = $payload['subject'] ?? 'Bevestiging van apparaatophaal';
        $template = $this->renderTemplate('pickup_confirmation', $payload);

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $template['html']);
    }

    public function sendPcBuildRelease(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload,
        array $attachments = []
    ): array {
        $subject = $payload['subject'] ?? 'Twój zestaw PC jest gotowy do odbioru';
        $template = $this->renderTemplate('pc_build_release', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText, $attachments);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeConfirmation(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload,
        array $attachments = []
    ): array {
        $subject = $payload['subject'] ?? 'Bevestiging intake afspraak';
        $template = $this->renderTemplate('intake_confirmation', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText, $attachments);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeArrivalAcknowledgement(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload
    ): array {
        $subject = $payload['subject'] ?? 'Ontvangstbevestiging service intake';
        $template = $this->renderTemplate('intake_arrival', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeRescheduled(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload
    ): array {
        $subject = $payload['subject'] ?? 'Nieuwe intake afspraak bevestigd';
        $template = $this->renderTemplate('intake_rescheduled', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeCancellation(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload
    ): array {
        $subject = $payload['subject'] ?? 'Bevestiging annulering intake afspraak';
        $template = $this->renderTemplate('intake_cancelled', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeNoShow(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload
    ): array {
        $subject = $payload['subject'] ?? 'We hebben u gemist bij uw intake';
        $template = $this->renderTemplate('intake_no_show', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendSms(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $template = $this->renderTemplate('sms_generic', $payload);
        $this->recordNotification($caseId, $customerId, 'sms', $recipient, $payload['subject'] ?? null, $template['text']);
    }

    private function recordNotification(
        ?int $caseId,
        ?int $customerId,
        string $channel,
        string $recipient,
        ?string $subject,
        string $body,
        string $status = 'sent',
        ?string $error = null,
        ?string $sentAt = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications (case_id, customer_id, channel, recipient, subject, body, status, error, sent_at, created_at) '
            . 'VALUES (:case_id, :customer_id, :channel, :recipient, :subject, :body, :status, :error, :sent_at, :created_at)'
        );

        $now = Clock::nowFormatted();
        $sentAtValue = $sentAt ?? ($status === 'sent' ? $now : null);

        $statement->execute([
            'case_id' => $caseId,
            'customer_id' => $customerId,
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => $status,
            'error' => $error,
            'sent_at' => $sentAtValue,
            'created_at' => $now,
        ]);

        $this->writeLog($channel, $recipient, $subject, $body, $now, $status, $error, $sentAtValue);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{html: string, text: string}
     */
    private function renderTemplate(string $type, array $payload): array
    {
        $safePayload = array_map(static function ($value): string {
            if ($value instanceof DateTimeImmutable) {
                return $value->format('d-m-Y H:i');
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value, JSON_THROW_ON_ERROR);
        }, $payload);

        $contact = [
            'email' => $safePayload['company_email'] ?? 'servicedesk@digivriend.nl',
            'phone' => $safePayload['company_phone'] ?? '033 - 785 4284',
            'address' => $safePayload['company_address'] ?? 'De Ganskuijl 103B · 3817 EZ Amersfoort',
            'website' => $safePayload['company_website'] ?? 'https://digivriend.nl',
        ];

        return match ($type) {
             'pickup_ready' => [
                'html' => $this->renderEmailLayout(
                    'Uw apparaat staat klaar voor afhalen',
                    'Uw apparaat staat klaar',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        sprintf('Goed nieuws! Uw apparaat %s staat voor u klaar in onze servicebalie. Neem uw ophaalcode mee zodat we u snel kunnen helpen.', $safePayload['device'] ?? 'uw apparaat'),
                        'U bent van harte welkom om een tijdstip te kiezen dat het beste past. Meld u bij aankomst bij onze receptie en wij regelen de rest.',
                    ],
                    [
                        'Apparaat' => $safePayload['device'] ?? 'Uw apparaat',
                        'Ophaalcode' => $safePayload['pickup_code'] ?? '—',
                        'Voorkeursmoment' => $safePayload['ready_date'] ?? 'Kies een geschikt moment',
                    ],
                    null,
                    [
                        'Ons team legt alles graag nog even uit en controleert samen met u de laatste details.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nUw apparaat (%s) staat klaar om opgehaald te worden. Gebruik ophaalcode %s en plan bij voorkeur uw bezoek op %s.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['device'] ?? 'uw apparaat',
                    $safePayload['pickup_code'] ?? '—',
                    $safePayload['ready_date'] ?? 'een geschikt moment'
                ),
            ],
            'pickup_confirmation' => [
                'html' => $this->renderEmailLayout(
                    'Wij hebben geregistreerd dat uw apparaat is opgehaald',
                    'Bedankt voor uw bezoek',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Wat fijn dat alles gelukt is! We hebben genoteerd dat het apparaat veilig is meegegeven.',
                        'Mocht u later nog vragen hebben over service of accessoires, dan horen we het graag.',
                    ],
                    [
                        'Ophaalcode' => $safePayload['pickup_code'] ?? '—',
                        'Datum van ophalen' => $safePayload['pickup_date'] ?? '—',
                    ],
                    null,
                    [
                        'Bewaar deze bevestiging gerust in uw administratie. Hij bevat alle gegevens over dit bezoek.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nWij hebben bevestigd dat uw apparaat met code %s op %s is opgehaald. Dank voor uw bezoek en tot een volgende keer!\n\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['pickup_code'] ?? '—',
                    $safePayload['pickup_date'] ?? '—'
                ),
            ],
            'intake_confirmation' => [
                'html' => $this->renderEmailLayout(
                    'Uw intake is succesvol ingepland',
                    'Bevestiging intake afspraak',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Dank voor het inplannen van uw bezoek. We kijken ernaar uit om u persoonlijk te ontvangen en meteen met uw apparaat aan de slag te gaan.',
                        'Neem dit bericht mee (digitaal of uitgeprint) zodat we uw intake razendsnel kunnen starten.',
                    ],
                    [
                        'Afspraakmoment' => $safePayload['appointment_at'] ?? 'Het afgesproken tijdstip',
                        'Referentiecode' => $safePayload['reference_code'] ?? '—',
                        'Beschrijving' => $safePayload['notes'] ?? '—',
                    ],
                    null,
                    [
                        'Komt het toch niet uit? Laat het ons weten, dan plannen we direct een nieuw moment voor u.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nBedankt voor het plannen van uw intake. Wij verwachten u op %s in onze vestiging. Neem deze bevestiging en uw apparaat mee. Uw referentiecode is %s.\n\nBeschrijving: %s\n\nTot snel,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het afgesproken tijdstip',
                    $safePayload['reference_code'] ?? '—',
                    $safePayload['notes'] ?? '—'
                ),
            ],
            'intake_arrival' => [
                'html' => $this->renderEmailLayout(
                    'We hebben uw apparaat ontvangen',
                    'Ontvangstbevestiging service intake',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Uw device is veilig geregistreerd in ons systeem. Onze technici starten direct met het onderzoek en houden u op de hoogte.',
                    ],
                    [
                        'Case / referentie' => $safePayload['reference_code'] ?? 'Uw case',
                        'Binnengebracht op' => $safePayload['appointment_at'] ?? 'Het afgesproken moment',
                    ],
                    null,
                    [
                        'U ontvangt bericht zodra we nieuwe bevindingen hebben of als we aanvullende informatie nodig hebben.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nWij bevestigen de ontvangst van uw apparaat voor case %s. Het toestel is op %s bij ons binnengebracht en het onderzoek start direct. U ontvangt een update zodra er nieuws is.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['reference_code'] ?? 'uw case',
                    $safePayload['appointment_at'] ?? 'het afgesproken moment'
                ),
            ],
            'intake_rescheduled' => [
                'html' => $this->renderEmailLayout(
                    'Uw intake is verplaatst',
                    'Nieuwe intake afspraak bevestigd',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Zoals afgestemd hebben wij de intake voor u verplaatst. Alle gegevens zijn bijgewerkt in onze planning.',
                    ],
                    [
                        'Nieuw afspraakmoment' => $safePayload['appointment_at'] ?? 'Het nieuwe tijdstip',
                        'Referentiecode' => $safePayload['reference_code'] ?? '—',
                    ],
                    null,
                    [
                        'Mocht u opnieuw willen schuiven, laat het ons gerust weten. We zoeken meteen mee naar het beste moment.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nZoals besproken hebben wij uw intake verplaatst naar %s. Uw referentiecode %s blijft ongewijzigd. Laat het ons weten als de planning opnieuw aangepast moet worden.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het nieuwe tijdstip',
                    $safePayload['reference_code'] ?? '—'
                ),
            ],
            'intake_cancelled' => [
                'html' => $this->renderEmailLayout(
                    'We hebben uw intake geannuleerd',
                    'Bevestiging annulering intake',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'We hebben uw bericht ontvangen en de afspraak volgens afspraak geannuleerd.',
                    ],
                    [
                        'Gepland moment' => $safePayload['appointment_at'] ?? 'Het geplande moment',
                        'Reden van annulering' => $safePayload['cancellation_reason'] ?? 'Geen reden opgegeven',
                    ],
                    null,
                    [
                        'Wanneer het weer uitkomt plannen we graag een nieuw bezoek. Neem gerust contact met ons op.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nUw intake afspraak van %s is geannuleerd. Reden: %s. Wanneer u later alsnog langskomt helpen we u graag verder. Neem gerust contact met ons op voor een nieuwe afspraak.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het geplande moment',
                    $safePayload['cancellation_reason'] ?? 'geen reden opgegeven'
                ),
            ],
            'intake_no_show' => [
                'html' => $this->renderEmailLayout(
                    'We hebben u gemist bij de intake',
                    'We hebben u gemist',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Jammer dat we elkaar hebben misgelopen. Geen zorgen: we helpen u graag alsnog verder.',
                    ],
                    [
                        'Gepland moment' => $safePayload['appointment_at'] ?? 'Het geplande moment',
                    ],
                    [
                        'Plan nieuwe intake',
                        $safePayload['reschedule_url'] ?? '',
                        'Kies eenvoudig een moment dat beter past.',
                    ],
                    [
                        'Zodra onze vernieuwde planner klaar is ontvangt u automatisch een nieuwe uitnodiging. Heeft u nu al hulp nodig? Laat het ons weten, dan zoeken we direct mee.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nWe hadden u graag ontvangen op %s, maar we hebben u helaas gemist. Jammer dat het niet is gelukt. Zodra onze nieuwe planner gereed is ontvangt u een link om eenvoudig een nieuwe intake te boeken. Heeft u nu al hulp nodig? Neem dan contact met ons op.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het geplande moment'
                ),
            ],
            'pc_build_release' => [
                'html' => $this->renderEmailLayout(
                    'Twój zestaw PC jest gotowy do odbioru',
                    'Zestaw PC gotowy!',
                    [
                        sprintf('Dzień dobry %s,', $safePayload['customer_name'] ?? 'klient'),
                        'Z przyjemnością informujemy, że Twój zestaw został złożony, przetestowany i jest gotowy do wydania.',
                    ],
                    [
                        'Zestaw' => $safePayload['build_reference'] ?? 'Twój zestaw',
                        'Sposób wydania' => $safePayload['delivery_method'] ?? 'Odbiór w salonie',
                        'Data wydania' => $safePayload['release_date'] ?? date('d-m-Y'),
                    ],
                    [
                        'Pobierz potwierdzenie',
                        $safePayload['release_document_url'] ?? '',
                        'Dokument znajdziesz również w załączniku.',
                    ],
                    [
                        'Jeśli masz dodatkowe pytania dotyczące zestawu lub chcesz omówić konfigurację, skontaktuj się z nami – chętnie pomożemy.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Dzień dobry %s,\n\nZestaw PC %s jest gotowy do przekazania. Sposób wydania: %s dnia %s. W załączniku znajdziesz potwierdzenie wydania. W razie pytań skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Digivriend",
                    $safePayload['customer_name'] ?? 'klient',
                    $safePayload['build_reference'] ?? 'Twój zestaw',
                    $safePayload['delivery_method'] ?? 'odbiór w salonie',
                    $safePayload['release_date'] ?? date('d-m-Y')
                ),
            ],
            'sms_generic' => [
                'html' => $this->renderPlainBlock($safePayload['body'] ?? ''),
                'text' => $safePayload['body'] ?? '',
            ],
            default => [
                'html' => $this->renderPlainBlock($safePayload['body'] ?? ''),
                'text' => $safePayload['body'] ?? '',
            ],
        };
    }

    private function renderEmailLayout(
        string $preheader,
        string $headline,
        array $introParagraphs,
        array $detailRows,
        ?array $cta,
        array $additionalParagraphs,
        array $contact
    ): string {
        $preheaderText = $this->escape($preheader);
        $headlineText = $this->escape($headline);
        $introHtml = $this->buildParagraphs($introParagraphs);
        $detailsHtml = $this->buildDetailRows($detailRows);
        $ctaHtml = $this->buildCtaBlock($cta);
        $additionalHtml = $this->buildParagraphs($additionalParagraphs, true);
        $contactHtml = $this->buildContactBlock($contact);
        $logoDataUri = $this->getLogoDataUri();

        $signatureHtml = '<div style="margin-top:32px;">'
            . '<p style="margin:0 0 6px; font-size:15px; line-height:1.6; color:#1c2333;">Met vriendelijke groet,</p>'
            . '<p style="margin:0; font-size:15px; line-height:1.6; color:#1c2333; font-weight:600;">Team Digivriend</p>'
            . '</div>';

        $additionalSection = $additionalHtml === '' ? '' : '<div style="margin-top:28px;">' . $additionalHtml . '</div>';

        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digivriend</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f5fb; font-family:'Helvetica Neue', Arial, sans-serif; color:#1c2333;">
    <span style="display:none!important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden;">$preheaderText</span>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; background-color:#f4f5fb;">
        <tr>
            <td style="padding:40px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; max-width:680px; margin:0 auto;">
                    <tr>
                        <td style="padding:0;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; background:#ffffff; border-radius:20px; overflow:hidden; box-shadow:0 24px 60px rgba(28,35,51,0.12);">
                                <tr>
                                    <td style="padding:40px 36px; background:linear-gradient(135deg,#0f1f3a,#2463eb 55%,#ec6625 115%);">
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;">
                                            <tr>
                                                <td style="padding-right:0;">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;">
                                                        <tr>
                                                            <td style="vertical-align:middle; width:1%;">
                                                                <img src="{$logoDataUri}" alt="Digivriend" style="display:block; height:40px; width:auto;">
                                                            </td>
                                                            <td style="vertical-align:middle; padding-left:16px;">
                                                                <span style="display:block; font-size:26px; font-weight:700; color:#ffffff; letter-spacing:0.32px;">Digivriend</span>
                                                                <span style="display:block; margin-top:6px; font-size:14px; font-weight:500; color:rgba(255,255,255,0.88); letter-spacing:0.1px;">Betrouwbare computerhulp aan huis</span>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-top:10px; font-size:16px; color:rgba(255,255,255,0.92); line-height:1.5;">$headlineText</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="height:4px; background:linear-gradient(90deg,#ec6625,#2463eb); padding:0; font-size:0; line-height:0;">&nbsp;</td>
                                </tr>
                                <tr>
                                    <td style="padding:36px 36px 28px;">
                                        $introHtml
                                        $detailsHtml
                                        $ctaHtml
                                        $additionalSection
                                        $signatureHtml
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:24px 36px 36px; background-color:#f7f8fc;">
                                        $contactHtml
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align:center; font-size:12px; color:#7b859b; padding:18px 12px 0;">
                            © $year Digivriend · Service met een glimlach
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    private function getLogoDataUri(): string
    {
        return self::LOGO_DATA_URI;
    }

    private function renderPlainBlock(string $content): string
    {
        $text = trim($content);

        if ($text === '') {
            return '<p style="margin:0; font-family:Helvetica, Arial, sans-serif; font-size:16px; line-height:1.6; color:#1c2333;">&nbsp;</p>';
        }

        return '<p style="margin:0; font-family:Helvetica, Arial, sans-serif; font-size:16px; line-height:1.6; color:#1c2333;">'
            . nl2br($this->escape($text))
            . '</p>';
    }

    private function buildParagraphs(array $paragraphs, bool $subtle = false): string
    {
        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            if (!is_string($paragraph)) {
                continue;
            }

            $trimmed = trim($paragraph);
            if ($trimmed === '') {
                continue;
            }

            $blocks[] = sprintf(
                '<p style="margin:0 0 %dpx; font-size:%s; line-height:1.65; color:#1c2333;">%s</p>',
                $subtle ? 16 : 18,
                $subtle ? '15px' : '16px',
                nl2br($this->escape($trimmed))
            );
        }

        return implode('', $blocks);
    }

    private function buildDetailRows(array $rows): string
    {
        $rowHtml = [];

        foreach ($rows as $label => $value) {
            if (!is_string($value)) {
                continue;
            }

            $valueTrimmed = trim($value);
            if ($valueTrimmed === '') {
                continue;
            }

            $labelText = $this->escape(is_string($label) ? $label : (string) $label);
            $valueText = nl2br($this->escape($valueTrimmed));

            $rowHtml[] = <<<HTML
<tr>
    <td style="padding:14px 18px; width:44%; font-size:14px; font-weight:600; color:#1c2333; background-color:#f1f3fb; border-bottom:1px solid #e1e5f2;">$labelText</td>
    <td style="padding:14px 18px; font-size:14px; color:#384152; background-color:#f8f9ff; border-bottom:1px solid #e1e5f2;">$valueText</td>
</tr>
HTML;
        }

        if ($rowHtml === []) {
            return '';
        }

        $lastIndex = array_key_last($rowHtml);
        if ($lastIndex !== null) {
            $rowHtml[$lastIndex] = str_replace('border-bottom:1px solid #e1e5f2;', 'border-bottom:none;', $rowHtml[$lastIndex]);
        }

        $rowsMarkup = implode('', $rowHtml);

        return <<<HTML
<div style="margin-top:28px;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; border-radius:14px; overflow:hidden; border:1px solid #e1e5f2;">
        $rowsMarkup
    </table>
</div>
HTML;
    }

    private function buildCtaBlock(?array $cta): string
    {
        if ($cta === null) {
            return '';
        }

        $label = isset($cta['label']) ? (string) $cta['label'] : (string) ($cta[0] ?? '');
        $url = isset($cta['url']) ? (string) $cta['url'] : (string) ($cta[1] ?? '');
        $subtext = isset($cta['subtext']) ? (string) $cta['subtext'] : (string) ($cta[2] ?? '');

        $hasLabel = trim($label) !== '';
        $hasUrl = trim($url) !== '';
        $hasSubtext = trim($subtext) !== '';

        if (!$hasLabel && !$hasSubtext) {
            return '';
        }

        $labelText = $this->escape($label);
        $subtextText = $hasSubtext ? nl2br($this->escape($subtext)) : '';

        if ($hasUrl && $hasLabel) {
            $urlText = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $subtextBlock = $hasSubtext
                ? '<p style="margin:14px 0 0; font-size:13px; color:rgba(255,255,255,0.9);">' . $subtextText . '</p>'
                : '';

            return <<<HTML
<div style="margin-top:30px; padding:26px; border-radius:16px; background:linear-gradient(120deg,#2463eb,#4dd0e1 60%,#ec6625 120%); color:#ffffff; text-align:center;">
    <a href="$urlText" style="display:inline-block; padding:14px 26px; background-color:#ffffff; color:#1c3faa; font-weight:600; font-size:15px; border-radius:999px; text-decoration:none; box-shadow:0 10px 25px rgba(12,54,140,0.25);">$labelText</a>
    $subtextBlock
</div>
HTML;
        }

        $content = '<p style="margin:0; font-size:15px; font-weight:600; color:#1c2333;">' . $labelText . '</p>';
        if ($hasSubtext) {
            $content .= '<p style="margin:8px 0 0; font-size:13px; color:#4f5d75;">' . $subtextText . '</p>';
        }

        return '<div style="margin-top:28px; padding:22px; border-radius:16px; background-color:#eef2ff;">' . $content . '</div>';
    }

    private function buildContactBlock(array $contact): string
    {
        $email = isset($contact['email']) ? trim((string) $contact['email']) : '';
        $phone = isset($contact['phone']) ? trim((string) $contact['phone']) : '';
        $address = isset($contact['address']) ? trim((string) $contact['address']) : '';
        $website = isset($contact['website']) ? trim((string) $contact['website']) : '';

        $items = [];
        if ($email !== '') {
            $items[] = '<span style="display:inline-block; margin-right:16px;"><span style="font-weight:600; color:#1c2333;">E-mail:</span> <a href="mailto:'
                . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" style="color:#2463eb; text-decoration:none;">' . $this->escape($email) . '</a></span>';
        }

        if ($phone !== '') {
            $sanitisedPhoneLink = preg_replace('/[^+\d]/', '', $phone);
            if ($sanitisedPhoneLink === null || $sanitisedPhoneLink === '') {
                $sanitisedPhoneLink = $phone;
            }

            $items[] = '<span style="display:inline-block; margin-right:16px;"><span style="font-weight:600; color:#1c2333;">Telefoon:</span> <a href="tel:'
                . htmlspecialchars($sanitisedPhoneLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" style="color:#2463eb; text-decoration:none;">' . $this->escape($phone) . '</a></span>';
        }

        if ($address !== '') {
            $items[] = '<span style="display:block; margin-top:8px; font-size:13px; color:#4f5d75;">' . $this->escape($address) . '</span>';
        }

        if ($website !== '') {
            $items[] = '<span style="display:inline-block; margin-top:8px;"><a href="'
                . htmlspecialchars($website, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" style="color:#2463eb; text-decoration:none;">' . $this->escape($website) . '</a></span>';
        }

        $itemsHtml = implode('<br>', $items);

        return '<div style="font-size:13px; line-height:1.7; color:#4f5d75;">'
            . '<p style="margin:0 0 10px; font-size:14px; font-weight:600; color:#1c2333;">Vragen? Wij staan voor u klaar.</p>'
            . ($itemsHtml !== '' ? $itemsHtml : '')
            . '</div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function convertHtmlToText(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $stripped = strip_tags($decoded);
        $normalisedWhitespace = preg_replace('/[ \t]+/', ' ', $stripped) ?? $stripped;
        $normalisedNewlines = preg_replace("/(\r\n|\r|\n)/", "\n", $normalisedWhitespace) ?? $normalisedWhitespace;
        $collapsedNewlines = preg_replace("/\n{3,}/", "\n\n", $normalisedNewlines) ?? $normalisedNewlines;

        return trim($collapsedNewlines);
    }

    private function writeLog(
        string $channel,
        string $recipient,
        ?string $subject,
        string $body,
        string $timestamp,
        string $status,
        ?string $error = null,
        ?string $sentAt = null
    ): void {
        $directory = __DIR__ . '/../../../storage/notifications';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = sprintf('%s/%s-%s.log', $directory, $channel, date('Ymd_His'));
        $contents = [
            'kanaal: ' . $channel,
            'ontvanger: ' . $recipient,
            'onderwerp: ' . ($subject ?? ''),
            'status: ' . $status,
            'aangemaakt: ' . $timestamp,
        ];

        if ($sentAt !== null) {
            $contents[] = 'verzonden: ' . $sentAt;
        }

        if ($error !== null && $error !== '') {
            $contents[] = 'fout: ' . $error;
        }

        $contents[] = 'bericht:';
        $contents[] = $body;
        $contents[] = str_repeat('-', 40);

        file_put_contents($filename, implode(PHP_EOL, $contents) . PHP_EOL, FILE_APPEND);
    }
    /**
     * @param array<int, array<string, string>> $attachments
     * @return array{success: bool, error: ?string}
     */
    private function sendEmail(
        string $recipient,
        string $subject,
        string $bodyHtml,
        string $bodyText = '',
        array $attachments = []
    ): array {
        $fromAddress = trim((string) Env::get('MAIL_FROM_ADDRESS', ''));
        if ($fromAddress === '') {
            $fromAddress = 'no-reply@digivriend.local';
        }
        $fromName = (string) Env::get('MAIL_FROM_NAME', 'Digivriend');

        $headers = [];
        $headers[] = 'From: ' . $this->formatAddress($fromAddress, $fromName);
        $headers[] = 'Reply-To: ' . $this->formatAddress($fromAddress, $fromName);
        $headers[] = 'MIME-Version: 1.0';

        $plainBody = trim($bodyText) !== '' ? $bodyText : $this->convertHtmlToText($bodyHtml);
        $plainBody = $plainBody === '' ? ' ' : $plainBody;
        $normalisedPlain = $this->normaliseLineEndings($plainBody);
        $normalisedHtml = $this->normaliseLineEndings($bodyHtml);

        if ($attachments === []) {
            $boundaryAlt = '=_Alt_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';

            $parts = [];
            $parts[] = '--' . $boundaryAlt;
            $parts[] = 'Content-Type: text/plain; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $normalisedPlain;
            $parts[] = '--' . $boundaryAlt;
            $parts[] = 'Content-Type: text/html; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $normalisedHtml;
            $parts[] = '--' . $boundaryAlt . '--';
            $parts[] = '';

            $message = implode("\r\n", $parts);
        } else {
            $boundaryMixed = '=_Part_' . bin2hex(random_bytes(16));
            $boundaryAlt = '=_Alt_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';

            $parts = [];
            $parts[] = '--' . $boundaryMixed;
            $parts[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';
            $parts[] = '';
            $parts[] = '--' . $boundaryAlt;
            $parts[] = 'Content-Type: text/plain; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $normalisedPlain;
            $parts[] = '--' . $boundaryAlt;
            $parts[] = 'Content-Type: text/html; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $normalisedHtml;
            $parts[] = '--' . $boundaryAlt . '--';

            foreach ($attachments as $attachment) {
                $path = $attachment['path'] ?? null;
                $content = $attachment['content'] ?? null;

                if ($path !== null) {
                    if (!is_file($path)) {
                        return ['success' => false, 'error' => sprintf('Załącznik %s nie istnieje.', $path)];
                    }

                    $fileContents = file_get_contents($path);
                    if ($fileContents === false) {
                        return ['success' => false, 'error' => sprintf('Nie można odczytać załącznika %s.', $path)];
                    }

                    $content = $fileContents;
                }

                if ($content === null) {
                    return ['success' => false, 'error' => 'Brak danych załącznika.'];
                }

                $name = (string) ($attachment['name'] ?? ($path !== null ? basename($path) : 'zalacznik.pdf'));
                $mime = (string) ($attachment['mime'] ?? 'application/octet-stream');
                $encodedContent = chunk_split(base64_encode(is_string($content) ? $content : (string) $content));

                $parts[] = '--' . $boundaryMixed;
                $parts[] = 'Content-Type: ' . $mime . '; name="' . $this->encodeHeader($name) . '"';
                $parts[] = 'Content-Transfer-Encoding: base64';
                $parts[] = 'Content-Disposition: attachment; filename="' . $this->encodeHeader($name) . '"';
                $parts[] = '';
                $parts[] = $this->normaliseLineEndings($encodedContent);
            }

            $parts[] = '--' . $boundaryMixed . '--';
            $message = implode("\r\n", $parts);
        }

        $mailer = strtolower((string) Env::get('MAIL_MAILER', 'log'));

        if ($mailer === 'smtp') {
            return $this->sendViaSmtp($recipient, $subject, $message, $headers, $fromAddress);
        }

        if ($mailer === 'log') {
            return ['success' => true, 'error' => null];
        }

        $sent = mail(
            $recipient,
            $this->encodeHeader($subject),
            str_replace("\r\n", "\n", $message),
            implode("\r\n", $headers)
        );

        return [
            'success' => $sent,
            'error' => $sent ? null : 'Wywołanie funkcji mail() nie powiodło się.',
        ];
    }

    private function sendViaSmtp(string $recipient, string $subject, string $message, array $headers, string $fromAddress): array
    {
        $host = trim((string) Env::get('MAIL_HOST', ''));
        $port = (int) Env::get('MAIL_PORT', 587);
        $username = (string) Env::get('MAIL_USERNAME', '');
        $password = (string) Env::get('MAIL_PASSWORD', '');
        $encryption = strtolower((string) Env::get('MAIL_ENCRYPTION', 'tls'));
        $timeout = (int) Env::get('MAIL_TIMEOUT', 30);
        $ehloDomain = (string) Env::get('MAIL_EHLO_DOMAIN', 'localhost');

        if ($host === '') {
            return ['success' => false, 'error' => 'Brak konfiguracji serwera SMTP (MAIL_HOST).'];
        }

        if (!in_array($encryption, ['tls', 'starttls', 'ssl', 'none', ''], true)) {
            return ['success' => false, 'error' => sprintf('Nieobsługiwany typ szyfrowania SMTP: %s', $encryption)];
        }

        $remoteSocket = sprintf('%s:%d', $host, $port);
        if ($encryption === 'ssl') {
            $remoteSocket = sprintf('ssl://%s:%d', $host, $port);
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => Env::get('MAIL_VERIFY_PEER', true),
                'verify_peer_name' => Env::get('MAIL_VERIFY_PEER_NAME', true),
                'allow_self_signed' => Env::get('MAIL_ALLOW_SELF_SIGNED', false),
            ],
        ]);

        $timeout = max($timeout, 5);
        $stream = @stream_socket_client($remoteSocket, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!is_resource($stream)) {
            return [
                'success' => false,
                'error' => sprintf('Połączenie SMTP nie powiodło się (%s:%d): %s', $host, $port, $errstr ?: 'nieznany błąd'),
            ];
        }

        stream_set_timeout($stream, $timeout);

        $response = $this->readSmtpResponse($stream);
        if (!$this->responseCodeIs($response, 220)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Nieprawidłowa odpowiedź serwera SMTP: %s', trim($response))];
        }

        $response = $this->sendSmtpCommand($stream, sprintf('EHLO %s', $ehloDomain));
        if (!$this->responseCodeIs($response, 250)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił komendę EHLO: %s', trim($response))];
        }

        if (in_array($encryption, ['tls', 'starttls'], true)) {
            $response = $this->sendSmtpCommand($stream, 'STARTTLS');
            if (!$this->responseCodeIs($response, 220)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił STARTTLS: %s', trim($response))];
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (!stream_socket_enable_crypto($stream, true, $cryptoMethod)) {
                fclose($stream);
                return ['success' => false, 'error' => 'Nie udało się zainicjować szyfrowania TLS.'];
            }

            $response = $this->sendSmtpCommand($stream, sprintf('EHLO %s', $ehloDomain));
            if (!$this->responseCodeIs($response, 250)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił ponowne EHLO: %s', trim($response))];
            }
        }

        if ($username !== '' && $password !== '') {
            $response = $this->sendSmtpCommand($stream, 'AUTH LOGIN');
            if (!$this->responseCodeIs($response, 334)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił AUTH LOGIN: %s', trim($response))];
            }

            $response = $this->sendSmtpCommand($stream, base64_encode($username));
            if (!$this->responseCodeIs($response, 334)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Błędna odpowiedź po przesłaniu użytkownika: %s', trim($response))];
            }

            $response = $this->sendSmtpCommand($stream, base64_encode($password));
            if (!$this->responseCodeIs($response, 235)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Logowanie SMTP nie powiodło się: %s', trim($response))];
            }
        }

        $envelopeFrom = $this->extractEmailAddress($fromAddress);
        if ($envelopeFrom === '') {
            fclose($stream);
            return ['success' => false, 'error' => 'Nieprawidłowy adres nadawcy dla SMTP.'];
        }

        $response = $this->sendSmtpCommand($stream, sprintf('MAIL FROM:<%s>', $envelopeFrom));
        if (!$this->responseCodeIs($response, 250)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił adres nadawcy: %s', trim($response))];
        }

        $recipients = array_filter(array_map(static fn (string $value): string => trim($value), preg_split('/[,;]/', $recipient) ?: []));
        if ($recipients === []) {
            $recipients = [trim($recipient)];
        }

        $headerRecipients = $recipients;

        $acceptedRecipients = 0;

        foreach ($recipients as $index => $rcpt) {
            if ($rcpt === '') {
                continue;
            }

            $rcptAddress = $this->extractEmailAddress($rcpt);
            if ($rcptAddress === '') {
                unset($headerRecipients[$index]);
                continue;
            }

            $response = $this->sendSmtpCommand($stream, sprintf('RCPT TO:<%s>', $rcptAddress));
            if (!$this->responseCodeIs($response, 250, 251)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił odbiorcę %s: %s', $rcpt, trim($response))];
            }

            $acceptedRecipients++;
        }

        if ($acceptedRecipients === 0) {
            fclose($stream);
            return ['success' => false, 'error' => 'Brak poprawnych odbiorców wiadomości SMTP.'];
        }

        $response = $this->sendSmtpCommand($stream, 'DATA');
        if (!$this->responseCodeIs($response, 354)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił komendę DATA: %s', trim($response))];
        }

        $smtpMessage = $this->buildSmtpMessage(array_values($headerRecipients), $subject, $message, $headers);
        $this->writeSmtpData($stream, $smtpMessage);

        $response = $this->readSmtpResponse($stream);
        if (!$this->responseCodeIs($response, 250)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił wiadomość: %s', trim($response))];
        }

        $this->sendSmtpCommand($stream, 'QUIT');
        fclose($stream);

        return ['success' => true, 'error' => null];
    }

    /**
     * @param string[] $recipients
     */
    private function buildSmtpMessage(array $recipients, string $subject, string $message, array $headers): string
    {
        $headerMap = [];
        $extraHeaders = [];

        foreach ($headers as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $parts = explode(':', $trimmed, 2);
            if (count($parts) === 2) {
                $key = strtolower(trim($parts[0]));
                $headerMap[$key] = trim($parts[0]) . ':' . $parts[1];
            } else {
                $extraHeaders[] = $trimmed;
            }
        }

        if (!isset($headerMap['date'])) {
            $headerMap = ['date' => 'Date: ' . date(DATE_RFC2822)] + $headerMap;
        }

        $formattedRecipients = implode(', ', array_filter($recipients, static fn (string $value): bool => $value !== ''));
        if ($formattedRecipients === '') {
            $formattedRecipients = 'undisclosed-recipients:;';
        }

        $headerMap['to'] = 'To: ' . $formattedRecipients;
        $headerMap['subject'] = 'Subject: ' . $this->encodeHeader($subject);

        $preferredOrder = ['date', 'from', 'reply-to', 'to', 'subject'];
        $ordered = [];

        foreach ($preferredOrder as $key) {
            if (isset($headerMap[$key])) {
                $ordered[] = $headerMap[$key];
                unset($headerMap[$key]);
            }
        }

        foreach ($headerMap as $value) {
            $ordered[] = $value;
        }

        foreach ($extraHeaders as $value) {
            $ordered[] = $value;
        }

        $headerBlock = implode("\r\n", array_map([$this, 'normaliseHeaderLine'], $ordered));

        $normalisedMessage = $this->normaliseLineEndings($message);

        $payload = $headerBlock . "\r\n\r\n" . $normalisedMessage;
        $payloadLines = explode("\r\n", $payload);
        $escapedLines = array_map(static function (string $line): string {
            if ($line !== '' && $line[0] === '.') {
                return '.' . $line;
            }

            return $line;
        }, $payloadLines);

        return implode("\r\n", $escapedLines) . "\r\n.";
    }

    private function normaliseLineEndings(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace("\n", "\r\n", $value);
    }

    private function normaliseHeaderLine(string $line): string
    {
        return $this->normaliseLineEndings($line);
    }

    private function readSmtpResponse($stream): string
    {
        $response = '';

        while (is_resource($stream) && !feof($stream)) {
            $line = fgets($stream, 515);
            if ($line === false) {
                break;
            }

            $response .= $line;

            if (strlen($line) < 4) {
                continue;
            }

            if ($line[3] === ' ') {
                break;
            }
        }

        return $response;
    }

    private function responseCodeIs(string $response, int ...$expected): bool
    {
        if ($response === '') {
            return false;
        }

        $code = (int) substr(trim($response), 0, 3);

        return in_array($code, $expected, true);
    }

    private function sendSmtpCommand($stream, string $command): string
    {
        fwrite($stream, $command . "\r\n");

        return $this->readSmtpResponse($stream);
    }

    private function writeSmtpData($stream, string $data): void
    {
        fwrite($stream, $data . "\r\n");
    }

    private function extractEmailAddress(string $address): string
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/<([^>]+)>/', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($trimmed, "'\"");
    }

    private function encodeHeader(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        return mb_encode_mimeheader($trimmed, 'UTF-8', 'Q', "\r\n");
    }

    private function formatAddress(string $address, ?string $name = null): string
    {
        $cleanAddress = trim($address);
        if ($name === null || trim($name) === '') {
            return $cleanAddress;
        }

        return sprintf('"%s" <%s>', $this->encodeHeader($name), $cleanAddress);
    }
}