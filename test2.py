class DocumentCreate(BaseModel):
    file_name: str
    file_path: str
    file_type: str
    user_id: int