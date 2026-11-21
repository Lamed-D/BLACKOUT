# BLACKOUT 공통 논리 스키마 & 시드 (모든 엔진 동일)

모든 엔진(PHP/Node/Django)은 **로컬 즉시 구동**을 위해 **SQLite 파일 DB**(`blackout.db`)를 사용한다.
아래 **논리 구조**를 동일하게 생성하고 동일한 시드 데이터를 넣는다. 타입은 SQLite에 맞게 치환하되
컬럼명·의미·시드 값은 동일하게 유지한다.

- 각 엔진은 부팅 시 `blackout.db`가 없거나 `users` 테이블이 없으면 **스키마 생성 + 시드**를 자동 수행(멱등).
- 시드가 끝난 기본 `blackout.db`는 **git에 커밋**하여 pull 즉시 동작하게 한다.
- SQLite라 SQL 인젝션 실습은 `sqlite_master`, `pragma_table_info()`를 사용(세 엔진 공통).
- cloudflared/터널은 포함하지 않는다(사용자가 추후 직접 추가). 모든 포트는 `127.0.0.1` 바인딩.

> ⚠️ 본 랩은 **의도적으로 취약**하다(학습 전용, 로컬에서만 구동). 비밀번호는 솔트 없는 MD5로 저장한다(취약점 재현).

## 테이블

### users
| 컬럼 | 타입(논리) | 비고 |
| --- | --- | --- |
| id | PK, auto |  |
| username | string(80), unique |  |
| password | string(255) | **MD5(비밀번호)**, 솔트 없음 (의도적 취약) |
| name | string(80) |  |
| email | string(120) |  |
| role | string(20) | `user` / `admin` (쿠키/매스어사인먼트 권한상승 대상) |
| bio | text | 저장형 XSS 대상 |
| points | int | 기본 1000 (상점 비즈니스 로직) |
| last_ip | string(50) |  |
| created_at | datetime |  |

### posts
| 컬럼 | 타입 | 비고 |
| id | PK | |
| title | string(200) | 저장형 XSS (이스케이프 안 함) |
| content | text | 저장형 XSS |
| author_id | int (FK users.id) | |
| author | string(80) | 비정규화 표시용 |
| views | int | |
| likes | int | |
| created_at / updated_at | datetime | |

### comments
| id PK · post_id(FK) · author_id(FK) · author string · content text(저장형 XSS) · parent_id(self FK, 대댓글) · created_at |

### notices
| id PK · title · content · author_id · author · views · created_at | 관리자만 작성(접근통제 실습) |

### files
| id PK · post_id(nullable) · notice_id(nullable) · filename(서버 저장명) · orig_name(원본명) · size · created_at |
| 무제한 업로드 / 경로탐색 다운로드 대상 |

### login_logs
| id PK · user_id · username · ip_address · user_agent · created_at | 관리자 화면에서 검색(LIKE) |

### post_likes
| id PK · post_id(FK) · user_id(FK) · UNIQUE(post_id,user_id) |

### products  (신규 · 상점)
| id PK · name · description · price(int) · stock(int) · created_at |

### orders  (신규 · 상점/비즈니스 로직)
| id PK · user_id(FK) · product_id(FK) · quantity(int) · unit_price(int) · total(int) · created_at |
| 가격/수량/합계가 **클라이언트 입력을 신뢰** → 가격·수량 조작, 음수 결제 실습 |

## 시드 데이터

### users (비밀번호는 평문 → 각 엔진이 MD5로 저장)
| username | password(평문) | name | email | role | points |
| --- | --- | --- | --- | --- | --- |
| admin | admin123 | 관리자 | admin@blackout.lab | admin | 999999 |
| testuser | testpass | 테스터 | test@blackout.lab | user | 1000 |
| alice | alice1234 | 앨리스 | alice@blackout.lab | user | 1500 |

MD5 참고값:
- admin123 → `0192023a7bbd73250516f069df18b500`
- testpass → `179ad45c6ce2cb97cf1029e212046e81`
- alice1234 → `d739111b266f6ec2f72c0b5740a6f374`

### notices
- (admin) `[공지] BLACKOUT 실습 카페 이용 안내` / 본문: "본 카페는 의도적으로 취약하게 제작된 모의해킹 실습 환경입니다. 학습 목적 외 사용 금지."

### posts
- (testuser) `가입 인사 드립니다!` / "반갑습니다. 웹 보안 스터디를 위해 가입했습니다 😊"
- (alice) `SQL Injection 학습 자료 공유` / "PortSwigger Academy의 SQL Injection 트랙을 추천합니다!"

### comments (post 1)
- (alice) "환영합니다! 함께 공부해요 :)"
- (admin) "잘 오셨습니다! 실습 랩을 마음껏 활용해 보세요."

### products
| name | description | price | stock |
| --- | --- | --- | --- |
| 실습 포인트 충전권 | 포인트 1000 충전 쿠폰 | 1000 | 100 |
| BLACKOUT 후드티 | 한정판 보안 스터디 굿즈 | 35000 | 20 |
| 프리미엄 멘토링 1회 | 1:1 웹해킹 멘토링 | 200000 | 5 |

## 비밀 플래그 (목표 확인용)
각 취약점 공격 성공 시 노출되는 형식: `BLACKOUT{<엔진>_<취약점>_<토큰>}`
예) `BLACKOUT{php_sqli_union_ok}`. 엔진별 `/api/user_data`·관리자 페이지 등에 민감정보로 심는다.
