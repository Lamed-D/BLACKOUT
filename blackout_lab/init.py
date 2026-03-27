import os
import shutil

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
UPLOADS_DIR = os.path.join(BASE_DIR, 'uploads')

def clean_uploads():
    print("[*] Cleaning up uploaded files in 'uploads/' directory...")
    if os.path.exists(UPLOADS_DIR):
        for filename in os.listdir(UPLOADS_DIR):
            file_path = os.path.join(UPLOADS_DIR, filename)
            try:
                if os.path.isfile(file_path) or os.path.islink(file_path):
                    os.unlink(file_path)
                elif os.path.isdir(file_path):
                    shutil.rmtree(file_path)
            except Exception as e:
                print(f"[-] Failed to delete {file_path}. Reason: {e}")
    else:
        os.makedirs(UPLOADS_DIR)
    print("[+] Uploads directory initialized.")

def reset_database():
    print("\n[*] Initializing Database...")
    db_path = os.path.join(BASE_DIR, 'database.db')
    if os.path.exists(db_path):
        try:
            os.remove(db_path)
            print("[+] Existing database.db deleted.")
        except Exception as e:
            print(f"[-] Failed to delete database.db. Reason: {e}")
            
    init_db_path = os.path.join(BASE_DIR, 'init_db.py')
    if os.path.exists(init_db_path):
        os.system(f'python "{init_db_path}"')
    else:
        print("[-] Error: init_db.py not found!")

if __name__ == '__main__':
    print("==================================================")
    print(" Web-Security Lab Factory Reset Script")
    print("==================================================")
    
    clean_uploads()
    reset_database()
    
    print("\n==================================================")
    print("[SUCCESS] All Clear! The environment has been fully initialized.")
    print("==================================================")
