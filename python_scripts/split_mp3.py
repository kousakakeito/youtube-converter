import sys
import os
import requests
import json

def identify_and_split(mp3_file_path, output_dir, shazam_api_key):
    url = "https://shazam-api6.p.rapidapi.com/shazam/recognize/"
    headers = {
        "x-rapidapi-key": shazam_api_key,
        "x-rapidapi-host": "shazam-api6.p.rapidapi.com",
        "Content-Type": "multipart/form-data; boundary=---011000010111000001101001"
    }
    files = {'file': open(mp3_file_path, 'rb')}
    response = requests.post(url, headers=headers, files=files)
    response_data = response.json()

    if response.status_code != 200:
        print(f"Failed to identify the song: {response_data}")
        return False

    segments = response_data['track']['sections'][0]['text']
    for i, segment in enumerate(segments):
        start_time = segment['start']
        end_time = segment['end']
        title = segment['title']
        sanitized_title = ''.join([c if c.isalnum() or c in 'ぁ-んァ-ヶー一-龠、"_-()' else '_' for c in title])
        
        output_file_path = os.path.join(output_dir, f"{sanitized_title}.mp3")
        ffmpeg_command = f'ffmpeg -i "{mp3_file_path}" -ss {start_time} -to {end_time} -c copy "{output_file_path}"'
        os.system(ffmpeg_command)

    return True

if __name__ == "__main__":
    if len(sys.argv) != 4:
        print("Usage: python split_mp3.py <mp3_file_path> <output_dir> <shazam_api_key>")
        sys.exit(1)

    mp3_file_path = sys.argv[1]
    output_dir = sys.argv[2]
    shazam_api_key = sys.argv[3]

    if not os.path.exists(output_dir):
        os.makedirs(output_dir)

    result = identify_and_split(mp3_file_path, output_dir, shazam_api_key)
    if result:
        print("MP3 file has been successfully split.")
    else:
        print("Failed to split the MP3 file.")
